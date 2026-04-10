<?php

namespace ProcessWire;

require_once __DIR__ . '/GitSyncGitHub.php';

/**
 * GitSync - Synchronize installed ProcessWire modules with GitHub repository branches.
 *
 * Allows developers to pull the latest code from any branch of a GitHub repository
 * directly into a module's directory, eliminating the need for manual FTP uploads.
 *
 * @property string $github_token GitHub personal access token
 * @property string $module_repos JSON-encoded array of module-to-repo mappings
 */
class GitSync extends Process {

    public static function getModuleInfo() {
        return [
            'title' => 'GitSync',
            'summary' => 'Synchronize installed ProcessWire modules with GitHub repository branches',
            'version' => '0.1.2',
            'author' => 'frameless Media',
            'href' => 'https://github.com/frameless-at/gitsync',
            'requires' => 'ProcessWire>=3.0.0',
            'icon' => 'github',
            'permission' => 'gitsync',
            'permissions' => [
                'gitsync' => 'Access the GitSync admin page',
            ],
            'page' => [
                'name' => 'gitsync',
                'parent' => 'setup',
                'title' => 'GitSync',
            ],
            'autoload' => true,
            'singular' => true,
        ];
    }

    /** @var GitSyncGitHub|null */
    protected $github = null;

    // =========================================================================
    // GitHub API Client
    // =========================================================================

    /**
     * Get the GitHub API client (lazy-initialized)
     */
    protected function getGitHub(): GitSyncGitHub {
        if ($this->github === null) {
            $this->github = new GitSyncGitHub($this->github_token ?: '');
        }
        return $this->github;
    }

    // =========================================================================
    // Unified Module Index (persistent via WireCache, shared by all searches)
    // =========================================================================

    const INDEX_CACHE_KEY = 'GitSync_module_index';

    protected function getModuleIndex(): array {
        $index = $this->wire('cache')->get(self::INDEX_CACHE_KEY);
        return is_array($index) ? $index : [];
    }

    protected function saveModuleIndex(array $index): void {
        $this->wire('cache')->save(self::INDEX_CACHE_KEY, $index, WireCache::expireNever);
    }

    protected function indexModules(array $results): void {
        $index = $this->getModuleIndex();
        $changed = false;
        foreach ($results as $result) {
            $mc = $result['module_class'] ?? '';
            if (empty($mc)) continue;
            $entry = [
                'full_name' => $result['full_name'],
                'owner' => $result['owner'],
                'repo' => $result['repo'],
                'description' => $result['description'] ?? '',
                'url' => $result['url'],
            ];
            // Migrate old single-entry format to array
            if (isset($index[$mc]) && !is_array($index[$mc][0] ?? null)) {
                $index[$mc] = [$index[$mc]];
            }
            if (!isset($index[$mc])) {
                $index[$mc] = [];
            }
            // Avoid duplicates
            $dominated = false;
            foreach ($index[$mc] as $existing) {
                if ($existing['full_name'] === $entry['full_name']) {
                    $dominated = true;
                    break;
                }
            }
            if (!$dominated) {
                $index[$mc][] = $entry;
                $changed = true;
            }
        }
        if ($changed) $this->saveModuleIndex($index);
    }

    protected function invalidateSearchCache(): void {
        $this->wire('cache')->delete(self::INDEX_CACHE_KEY);
    }

    /**
     * Search GitHub repos by module class name (for "Link installed module").
     * Checks unified index first, falls back to API, updates index.
     */
    protected function searchGitHubRepos(string $moduleClass): array {
        $index = $this->getModuleIndex();
        if (isset($index[$moduleClass])) {
            $cached = $index[$moduleClass];
            // Migrate old single-entry format
            if (!is_array($cached[0] ?? null)) {
                $cached = [$cached];
            }
            return $cached;
        }

        $github = $this->getGitHub();
        $results = [];
        $seen = [];

        // Code Search for each known owner (finds private repos even if repo name differs)
        $checkedOwners = [];
        foreach ($this->getModuleRepos() as $r) {
            if (empty($r['owner']) || isset($checkedOwners[$r['owner']])) continue;
            $checkedOwners[$r['owner']] = true;
            try {
                $found = $github->findRepoByModuleClass($r['owner'], $moduleClass);
                if ($found && !isset($seen[$found['full_name']])) {
                    $seen[$found['full_name']] = true;
                    $found['module_class'] = $moduleClass;
                    $results[] = $found;
                }
            } catch (\Throwable $e) {}
        }

        // Public search — only if known-owner search found nothing
        if (empty($results)) {
            // Code Search by filename (finds repos where name ≠ class name)
            try {
                foreach ($github->findPublicReposByModuleClass($moduleClass) as $sr) {
                    if (isset($seen[$sr['full_name']])) continue;
                    $seen[$sr['full_name']] = true;
                    $sr['module_class'] = $moduleClass;
                    $results[] = $sr;
                }
            } catch (\Throwable $e) {}

            // Repository Search as fallback (for repos not in Code Search index)
            if (empty($results)) {
                try {
                    foreach ($github->searchRepositories($moduleClass, 8) as $sr) {
                        if (isset($seen[$sr['full_name']])) continue;
                        if (!$github->repoHasModuleFile($sr['owner'], $sr['repo'], $moduleClass)) continue;
                        $seen[$sr['full_name']] = true;
                        $sr['module_class'] = $moduleClass;
                        $results[] = $sr;
                    }
                } catch (\Throwable $e) {}
            }
        }

        $this->indexModules($results);
        return $results;
    }


    // =========================================================================
    // Bootstrap: URL Hook for Webhook
    // =========================================================================

    /**
     * Register the webhook URL hook (runs on every request because autoload=true,
     * but only adds a single lightweight hook – no performance impact)
     */
    public function init() {
        parent::init();
        if (!empty($this->webhook_secret)) {
            $this->checkWebhookRequest();
        }
    }

    /**
     * Check if the current request is a webhook call and handle it before PW routing kicks in
     */
    protected function checkWebhookRequest() {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $path = rtrim($path, '/') . '/';
        $expected = rtrim($this->wire('config')->urls->root, '/') . '/gitsync-webhook/';
        if ($path !== $expected) return;

        $this->handleWebhookRequest();
        exit;
    }

    /**
     * Handle GitHub webhook payload
     */
    protected function handleWebhookRequest() {

        // Only accept POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Method Not Allowed']);
            exit;
        }

        $log = $this->wire('log');

        // Verify HMAC signature
        $signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
        if (empty($signatureHeader)) {
            http_response_code(403);
            $log->save('gitsync', '[webhook] Rejected: missing X-Hub-Signature-256 header');
            echo json_encode(['error' => 'Missing signature']);
            exit;
        }

        $payload = file_get_contents('php://input');
        if (empty($payload)) {
            http_response_code(400);
            echo json_encode(['error' => 'Empty payload']);
            exit;
        }

        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $this->webhook_secret);
        if (!hash_equals($expectedSignature, $signatureHeader)) {
            http_response_code(403);
            $log->save('gitsync', '[webhook] Rejected: invalid HMAC signature');
            echo json_encode(['error' => 'Invalid signature']);
            exit;
        }

        // Parse payload
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            exit;
        }

        header('Content-Type: application/json');

        // Handle ping event
        $ghEvent = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
        if ($ghEvent === 'ping') {
            http_response_code(200);
            echo json_encode(['ok' => true, 'message' => 'pong']);
            exit;
        }

        // Only handle push events
        if ($ghEvent !== 'push') {
            http_response_code(200);
            echo json_encode(['ok' => true, 'message' => "Ignored event: {$ghEvent}"]);
            exit;
        }

        // Extract repo and branch from push event
        $repoFullName = $data['repository']['full_name'] ?? '';
        $ref = $data['ref'] ?? '';

        if (empty($repoFullName) || empty($ref)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing repository or ref']);
            exit;
        }

        $branch = preg_replace('#^refs/heads/#', '', $ref);
        if (empty($branch) || $branch === $ref) {
            http_response_code(200);
            echo json_encode(['ok' => true, 'message' => 'Not a branch push, ignored']);
            exit;
        }

        list($pushOwner, $pushRepo) = explode('/', $repoFullName, 2);

        // Find matching module mappings and sync
        $repos = $this->getModuleRepos();
        $synced = [];

        foreach ($repos as $id => $repo) {
            if (
                strcasecmp($repo['owner'], $pushOwner) === 0
                && strcasecmp($repo['repo'], $pushRepo) === 0
                && $repo['current_branch'] === $branch
            ) {
                try {
                    $result = $this->performSync($id, $repo, $branch, 'webhook');
                    $synced[] = ['module' => $repo['module_class'], 'result' => $result];
                } catch (\Throwable $e) {
                    $log->save('gitsync', "[webhook] Sync FAILED for {$repo['module_class']}: " . $e->getMessage());
                    $synced[] = ['module' => $repo['module_class'], 'error' => $e->getMessage()];
                }
            }
        }

        http_response_code(200);

        if (empty($synced)) {
            $log->save('gitsync', "[webhook] Push to {$repoFullName}:{$branch} – no matching mappings");
            echo json_encode(['ok' => true, 'message' => "No mappings match {$repoFullName}:{$branch}", 'synced' => []]);
        } else {
            echo json_encode(['ok' => true, 'synced' => $synced]);
        }
        exit;
    }

    // =========================================================================
    // Module Repos Data Helpers
    // =========================================================================

    /**
     * Get all module-to-repo mappings
     */
    protected function getModuleRepos(): array {
        $json = $this->module_repos;
        if (empty($json)) return [];
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Save module-to-repo mappings
     */
    protected function saveModuleRepos(array $repos): void {
        $data = [];
        foreach ($repos as $i => $repo) {
            $data[$i] = $repo;
            $data[$i]['id'] = $i;
        }
        $this->module_repos = json_encode(array_values($data));
        $this->wire('modules')->saveConfig($this, 'module_repos', $this->module_repos);
    }

    /**
     * Get a single repo mapping by index
     */
    protected function getRepoById(int $id): ?array {
        $repos = $this->getModuleRepos();
        return $repos[$id] ?? null;
    }

    // =========================================================================
    // Admin Page: Module List (default view)
    // =========================================================================

    /**
     * Main admin page - list all registered module-repo mappings
     */
    public function ___execute(): string {
        $modules = $this->wire('modules');

        if (empty($this->github_token)) {
            $this->warning(
                $this->_('No GitHub token configured.') . ' ' .
                $this->_('Only public repositories will work, with a limit of 60 API requests per hour.') . ' ' .
                sprintf('<a href="%s">%s</a>',
                    $modules->getModuleEditUrl($this),
                    $this->_('Configure token')
                ), 
            Notice::allowMarkup);
        }

        // Check write permissions on site/modules/
        if (!$this->isSiteModulesWritable()) {
            $modulesDir = $this->wire('config')->paths->siteModules;
            $this->error(sprintf(
                $this->_('GitSync cannot write to site/modules/ (owner: %1$s, permissions: %2$s, PHP user: %3$s). Set permissions to 770 on the module directories you want to sync via your FTP client (right-click → Properties → 770 → apply recursively).'),
                $this->getFileOwner($modulesDir),
                substr(sprintf('%o', fileperms($modulesDir)), -4),
                $this->getSystemUser()
            ));
        }

        $repos = $this->getModuleRepos();

        /** @var MarkupAdminDataTable $table */
        $table = $modules->get('MarkupAdminDataTable');
        $table->setEncodeEntities(false);
        $table->headerRow([
            $this->_('Module'),
            $this->_('Repository'),
            $this->_('Branch'),
            $this->_('Last Synced'),
            $this->_('Actions'),
        ]);

        if (empty($repos)) {
            $table->row([
                $this->_('No modules registered yet. Click "Link Module" to get started.'),
                '', '', '', ''
            ]);
        }

        foreach ($repos as $id => $repo) {
            $repoUrl = "https://github.com/{$repo['owner']}/{$repo['repo']}";
            $branchLabel = $repo['current_branch'] ?? '-';
            $lastSynced = !empty($repo['last_synced'])
                ? wireRelativeTimeStr($repo['last_synced'])
                : $this->_('never');

            // Webhook badge: only if this repo has actually received a webhook sync
            $webhookBadge = '';
            if (!empty($repo['webhook_active'])) {
                $webhookBadge = ' <span style="background:#1565C0;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px" title="' . $this->_('This module is auto-synced via GitHub webhook') . '"><i class="fa fa-bolt"></i> webhook</span>';
            }

            $actions = sprintf(
                '<a href="./branches/?id=%d" class="pw-panel-links"><i class="fa fa-code-fork"></i> %s</a> &nbsp; ',
                $id, $this->_('Branches')
            );
            $actions .= sprintf(
                '<a href="./delete/?id=%d" onclick="return confirm(\'%s\')"><i class="fa fa-chain-broken"></i> %s</a>',
                $id, $this->_('Are you sure?'), $this->_('Unlink')
            );

            $moduleEditUrl = $modules->getModuleEditUrl($repo['module_class']);

            $table->row([
                "<a href='{$moduleEditUrl}'><strong>{$this->wire('sanitizer')->entities($repo['module_class'])}</strong></a>",
                "<a href='{$repoUrl}' target='_blank'>{$this->wire('sanitizer')->entities($repo['owner'])}/{$this->wire('sanitizer')->entities($repo['repo'])}</a>",
                $this->wire('sanitizer')->entities($branchLabel) . $webhookBadge,
                $lastSynced,
                $actions,
            ]);
        }

        $out = $table->render();

        // Add Module button
        /** @var InputfieldButton $btn */
        $btn = $modules->get('InputfieldButton');
        $btn->attr('id', 'btn-add-module');
        $btn->href = './add/';
        $btn->icon = 'plus-circle';
        $btn->value = $this->_('Link Module');
        $out .= '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1em">';
        $out .= $btn->render();

        // Webhook Credentials link + modal
        $sanitizer = $this->wire('sanitizer');
        $webhookUrl = rtrim($this->wire('config')->urls->httpRoot, '/') . '/gitsync-webhook/';

        $modalContent = '';
        if (empty($this->webhook_secret)) {
            $configUrl = $modules->getModuleEditUrl($this);
            $modalContent .= '<div class="uk-alert uk-alert-warning">'
                . '<i class="fa fa-exclamation-triangle"></i> '
                . sprintf(
                    $this->_('No webhook secret configured. %sSet one in the module settings%s first.'),
                    "<a href='{$configUrl}'>", '</a>'
                )
                . '</div>';
        }

        $modalContent .= '<div class="uk-margin">';
        $modalContent .= '<label class="uk-form-label">' . $this->_('Payload URL') . '</label>';
        $modalContent .= '<input type="text" class="uk-input uk-form-width-1-1" readonly value="' . $sanitizer->entities($webhookUrl) . '" onclick="this.select()" style="font-family:monospace">';
        $modalContent .= '</div>';

        if (!empty($this->webhook_secret)) {
            $modalContent .= '<div class="uk-margin">';
            $modalContent .= '<label class="uk-form-label">' . $this->_('Secret') . '</label>';
            $modalContent .= '<input type="text" class="uk-input uk-form-width-1-1" readonly value="' . $sanitizer->entities($this->webhook_secret) . '" onclick="this.select()" style="font-family:monospace">';
            $modalContent .= '</div>';
        }

        $modalContent .= '<div class="uk-margin uk-text-small uk-text-muted">';
        $modalContent .= $this->_('Repository → Settings → Webhooks → Add webhook') . '<br>';
        $modalContent .= $this->_('Content type: application/json — Events: Just the push event');
        $modalContent .= '</div>';

        $out .= '<a href="#gitsync-webhook-modal" uk-toggle><i class="fa fa-bolt"></i> ' . $this->_('Webhook Credentials') . '</a>';
        $out .= '</div>';
        $out .= '<div id="gitsync-webhook-modal" uk-modal>';
        $out .= '<div class="uk-modal-dialog uk-modal-body">';
        $out .= '<h2 class="uk-modal-title">' . $this->_('Webhook Credentials') . '</h2>';
        $out .= $modalContent;
        $out .= '<p class="uk-text-right"><button class="uk-button uk-button-default uk-modal-close" type="button">' . $this->_('Close') . '</button></p>';
        $out .= '</div></div>';

        // Rate limit info
        $rateLimit = $this->getGitHub()->getRateLimit();
        if ($rateLimit['remaining'] !== null) {
            $out .= "<p class='uk-text-small uk-text-muted'>" . sprintf(
                $this->_('GitHub API: %d requests remaining (resets %s)'),
                $rateLimit['remaining'],
                $rateLimit['reset'] ? wireRelativeTimeStr($rateLimit['reset']) : '-'
            ) . "</p>";
        }

        return $out;
    }

    // =========================================================================
    // Admin Page: Add Module
    // =========================================================================

    /**
     * Add a new module-to-repo mapping (link installed or install new)
     */
    public function ___executeAdd(): string {
        // AJAX: search GitHub for repositories
        if ($this->wire('config')->ajax && !empty($_GET['search'])) {
            return $this->handleAjaxSearch();
        }

        $this->headline($this->_('Link Module'));
        $this->breadcrumb('../', $this->_('GitSync'));

        $input = $this->wire('input');
        $registeredModules = array_column($this->getModuleRepos(), 'module_class');
        $moduleLists = $this->buildModuleLists($registeredModules);
        $siteModules = $moduleLists['site'];
        $coreModules = $moduleLists['core'];

        // POST: Link installed module
        if ($input->requestMethod('POST') && $input->post('submit_link')) {
            return $this->handleLinkModule($siteModules + $coreModules);
        }

        // POST: Install new module from GitHub
        if ($input->requestMethod('POST') && $input->post('submit_install')) {
            return $this->handleInstallModule($registeredModules);
        }

        return $this->renderAddForm($siteModules, $coreModules);
    }

    /**
     * Handle AJAX search for GitHub repositories
     */
    protected function handleAjaxSearch(): string {
        header('Content-Type: application/json');
        $query = $this->sanitizeBranchName($_GET['search']);
        if (strlen($query) < 2) {
            echo json_encode([]);
            return '';
        }
        try {
            echo json_encode($this->searchGitHubRepos($query));
        } catch (\Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        return '';
    }

    /**
     * Build lists of site and core modules available for linking
     */
    protected function buildModuleLists(array $registeredModules): array {
        $modules = $this->wire('modules');
        $siteModules = [];
        $coreModules = [];

        foreach ($modules as $module) {
            $info = $modules->getModuleInfoVerbose($module);
            $className = $info['name'];
            if (in_array($className, $registeredModules)) continue;

            $modulePath = $modules->getModuleFile($module);
            $isCore = strpos($modulePath, $this->wire('config')->paths->wire) === 0;

            $entry = [
                'title' => $info['title'] ?? $className,
                'author' => $info['author'] ?? '',
                'href' => $info['href'] ?? '',
                'core' => $isCore,
            ];

            if ($isCore) {
                $coreModules[$className] = $entry;
            } else {
                $siteModules[$className] = $entry;
            }
        }

        ksort($siteModules);
        ksort($coreModules);

        return ['site' => $siteModules, 'core' => $coreModules];
    }

    /**
     * Handle POST: link an installed module to a GitHub repo
     */
    protected function handleLinkModule(array $allModules): string {
        $input = $this->wire('input');
        $moduleClass = $this->wire('sanitizer')->name($input->post('module_class'));

        if (empty($moduleClass) || !isset($allModules[$moduleClass])) {
            $this->error($this->_('Please select a module.'));
            $this->wire('session')->redirect('./');
            return '';
        }

        $repoUrl = trim($input->post('repo_url') ?? '');
        $parsed = !empty($repoUrl) ? $this->parseGitHubUrl($repoUrl) : null;

        if (!$parsed) {
            $modHref = $allModules[$moduleClass]['href'] ?? '';
            $parsed = $this->parseGitHubUrl($modHref);
        }

        $owner = $parsed['owner'] ?? '';
        $repo = $parsed['repo'] ?? '';

        if (empty($owner) || empty($repo)) {
            $this->error($this->_('Please enter a valid GitHub repository URL (e.g. https://github.com/owner/repo).'));
            $this->wire('session')->redirect('./');
            return '';
        }

        if (!$this->getGitHub()->isRepoAccessible($owner, $repo)) {
            $this->error($this->_('Could not access GitHub repository. Check your token permissions.'));
            $this->wire('session')->redirect('./');
            return '';
        }

        $repos = $this->getModuleRepos();
        $repos[] = [
            'module_class' => $moduleClass,
            'owner' => $owner,
            'repo' => $repo,
            'current_branch' => '',
            'last_synced' => '',
            'last_commit_sha' => '',
        ];
        $this->saveModuleRepos($repos);
        $this->invalidateSearchCache();

        $this->message(sprintf($this->_('Module "%s" linked to %s/%s.'), $moduleClass, $owner, $repo));
        $this->wire('session')->redirect('../');
        return '';
    }

    /**
     * Handle POST: install a new module from GitHub and link it
     */
    protected function handleInstallModule(array $registeredModules): string {
        $modules = $this->wire('modules');
        $input = $this->wire('input');

        $this->wire('session')->CSRF->validate();

        $repoUrl = trim($input->post('install_repo_url'));
        $parsed = $this->parseGitHubUrl($repoUrl);
        $owner = $parsed['owner'] ?? '';
        $repoName = $parsed['repo'] ?? '';

        if (empty($owner) || empty($repoName)) {
            $this->error($this->_('Please enter a valid GitHub repository URL (e.g. https://github.com/owner/repo).'));
            $this->wire('session')->redirect('./');
            return '';
        }

        try {
            $github = $this->getGitHub();
            $log = $this->wire('log');

            // Resolve branch and detect module class via API
            $defaultBranch = $github->resolveDefaultBranch($owner, $repoName);
            $moduleClass = $github->detectModuleClass($owner, $repoName, $defaultBranch);

            if (!$moduleClass) {
                $this->error($this->_('No ProcessWire module found in this repository (missing .module.php or .module file in the repository root).'));
                $this->wire('session')->redirect('./');
                return '';
            }

            $log->save('gitsync', "[install] Detected module: {$moduleClass} from {$owner}/{$repoName} branch={$defaultBranch}");

            if (in_array($moduleClass, $registeredModules)) {
                $this->error(sprintf($this->_('Module "%s" is already linked in GitSync.'), $moduleClass));
                $this->wire('session')->redirect('./');
                return '';
            }

            $targetDir = $this->wire('config')->paths->siteModules . $moduleClass . '/';
            $alreadyExists = is_dir($targetDir);
            $downloaded = 0;

            if (!$alreadyExists) {
                $remoteTree = $github->getTree($owner, $repoName, $defaultBranch);
                $this->wire('files')->mkdir($targetDir, true);

                foreach ($remoteTree as $entry) {
                    if ($entry['type'] !== 'blob') continue;
                    if (strpos($entry['path'], '..') !== false || $entry['path'][0] === '/') continue;

                    $filePath = $targetDir . $entry['path'];
                    $fileDir = dirname($filePath);
                    if (!is_dir($fileDir)) {
                        $this->wire('files')->mkdir($fileDir, true);
                    }

                    $content = $github->downloadBlob($owner, $repoName, $entry['sha']);
                    file_put_contents($filePath, $content);
                    $downloaded++;
                }
            }

            if (!$alreadyExists) {
                $modules->resetCache();
                $modules->refresh();

                if (!$modules->isInstalled($moduleClass) && $modules->isInstallable($moduleClass)) {
                    $modules->install($moduleClass);
                    $this->message(sprintf(
                        $this->_('Module "%s" downloaded (%d files), installed and linked to %s/%s.'),
                        $moduleClass, $downloaded, $owner, $repoName
                    ));
                } else {
                    $this->warning(sprintf(
                        $this->_('Module "%s" downloaded (%d files) from %s/%s. Please install manually via Modules > Refresh > Install.'),
                        $moduleClass, $downloaded, $owner, $repoName
                    ));
                }
            } else {
                $this->message(sprintf(
                    $this->_('Module "%s" linked to %s/%s.'),
                    $moduleClass, $owner, $repoName
                ));
            }

            // Get commit SHA — try API, fall back to 'unknown' for inaccessible repos
            $commitSha = '';
            try {
                $branchInfo = $github->getBranch($owner, $repoName, $defaultBranch);
                $commitSha = $branchInfo['sha'];
            } catch (\Throwable $e) {
                $commitSha = 'zipball-install';
            }

            $repos = $this->getModuleRepos();
            $repos[] = [
                'module_class' => $moduleClass,
                'owner' => $owner,
                'repo' => $repoName,
                'current_branch' => $defaultBranch,
                'last_synced' => date('c'),
                'last_commit_sha' => $commitSha,
            ];
            $this->saveModuleRepos($repos);
            $this->invalidateSearchCache();

            $this->wire('log')->save('gitsync', sprintf(
                '[discover] Installed "%s" from %s/%s (%s) – %d files',
                $moduleClass, $owner, $repoName, $defaultBranch, $downloaded
            ));

            $this->wire('session')->redirect('../');
            return '';

        } catch (GitSyncException $e) {
            if (strpos($e->getMessage(), 'not found') !== false) {
                $this->invalidateSearchCache();
            }
            $this->error($this->_('Installation failed: ') . $e->getMessage());
            $this->wire('session')->redirect('./');
            return '';
        } catch (\Throwable $e) {
            $this->error($this->_('Installation failed: ') . $e->getMessage());
            $this->wire('session')->redirect('./');
            return '';
        }
    }

    /**
     * Render the "Link Module" / "Install Module" form
     */
    protected function renderAddForm(array $siteModules, array $coreModules): string {
        $modules = $this->wire('modules');

        /** @var InputfieldForm $form */
        $form = $modules->get('InputfieldForm');
        $form->attr('method', 'post');
        $form->attr('action', './');
        $form->attr('id', 'GitSyncAddForm');

        // --- Fieldset 1: Link installed module ---
        /** @var InputfieldFieldset $fs */
        $fs = $modules->get('InputfieldFieldset');
        $fs->label = $this->_('Link installed module');
        $fs->icon = 'link';
        $fs->collapsed = Inputfield::collapsedNo;

        /** @var InputfieldRadios $f */
        $f = $modules->get('InputfieldRadios');
        $f->attr('name', 'module_source');
        $f->attr('id', 'gitsync-module-source');
        $f->label = $this->_('Module source');
        $f->addOption('site', $this->_('Site modules'));
        $f->addOption('core', $this->_('Core modules'));
        $f->attr('value', 'site');
        $f->optionColumns = 0;
        $f->columnWidth = 30;
        $f->collapsed = Inputfield::collapsedNever;
        $fs->add($f);

        /** @var InputfieldSelect $f */
        $f = $modules->get('InputfieldSelect');
        $f->attr('name', 'module_class');
        $f->attr('id', 'gitsync-module-select');
        $f->label = $this->_('Module');
        $f->columnWidth = 70;
        $f->addOption('', '');

        foreach ($siteModules as $className => $modInfo) {
            $label = $className;
            if ($modInfo['title'] !== $className) {
                $label .= " ({$modInfo['title']})";
            }
            $f->addOption($className, $label, ['data-core' => '0']);
        }

        foreach ($coreModules as $className => $modInfo) {
            $label = $className;
            if ($modInfo['title'] !== $className) {
                $label .= " ({$modInfo['title']})";
            }
            $f->addOption($className, $label, ['data-core' => '1']);
        }
        $f->appendMarkup = '<input type="hidden" name="repo_url" id="gitsync-repo-url" value="">'
            . '<div id="gitsync-repo-status" style="margin-top:8px"></div>';
        $fs->add($f);

        /** @var InputfieldSubmit $f */
        $f = $modules->get('InputfieldSubmit');
        $f->attr('name', 'submit_link');
        $f->attr('value', $this->_('Link'));
        $f->attr('id', 'gitsync-link-submit');
        $f->icon = 'link';
        $fs->add($f);

        $form->add($fs);

        // --- Fieldset 2: Install new module from GitHub ---
        /** @var InputfieldFieldset $fs */
        $fs = $modules->get('InputfieldFieldset');
        $fs->label = $this->_('Install new module from GitHub');
        $fs->icon = 'download';
        $fs->collapsed = Inputfield::collapsedYes;

        /** @var InputfieldURL $f */
        $f = $modules->get('InputfieldURL');
        $f->attr('name', 'install_repo_url');
        $f->attr('id', 'gitsync-install-url');
        $f->label = $this->_('GitHub Repository URL');
        $f->description = $this->_('Paste the URL of a GitHub repository containing a ProcessWire module.');
        $f->attr('placeholder', 'https://github.com/owner/repo');
        $f->collapsed = Inputfield::collapsedNever;
        $fs->add($f);

        /** @var InputfieldSubmit $f */
        $f = $modules->get('InputfieldSubmit');
        $f->attr('name', 'submit_install');
        $f->attr('value', $this->_('Install & Link'));
        $f->icon = 'download';
        $f->attr('id', 'gitsync-discover-submit');
        $fs->add($f);

        $form->add($fs);

        // Build module metadata for JS config
        $moduleData = [];
        foreach ($siteModules + $coreModules as $className => $modInfo) {
            $entry = ['author' => $modInfo['author']];
            if (!empty($modInfo['href']) && strpos($modInfo['href'], 'github.com') !== false) {
                $entry['href'] = $modInfo['href'];
            }
            $moduleData[$className] = $entry;
        }

        $jsConfig = json_encode([
            'moduleData' => $moduleData,
            'ajaxUrl' => './?' . http_build_query(['id' => 0]),
            'changeLabel' => $this->_('change'),
            'searchingLabel' => $this->_('Searching GitHub...'),
            'noReposLabel' => $this->_('No repositories found.'),
        ], JSON_UNESCAPED_SLASHES);

        $jsUrl = $this->wire('config')->urls->siteModules . 'GitSync/GitSyncAdd.js';

        $form->appendMarkup = "<style>.gitsync-hidden-btn{display:none!important}</style>
<script>window.GitSyncConfig = {$jsConfig};</script>
<script src=\"{$jsUrl}\"></script>";

        return $form->render();
    }

    // =========================================================================
    // Admin Page: Delete Module Mapping
    // =========================================================================

    /**
     * Delete a module-to-repo mapping
     */
    public function ___executeDelete(): string {
        $id = (int)$this->wire('input')->get('id');
        $repo = $this->getRepoById($id);

        if (!$repo) {
            $this->error($this->_('Module mapping not found.'));
            $this->wire('session')->redirect('../');
            return '';
        }

        $repos = $this->getModuleRepos();
        array_splice($repos, $id, 1);
        $this->saveModuleRepos($repos);
        $this->invalidateSearchCache();

        $this->message(sprintf($this->_('Module "%s" removed from GitSync.'), $repo['module_class']));
        $this->wire('session')->redirect('../');
        return '';
    }

    // =========================================================================
    // Admin Page: Branch List
    // =========================================================================

    /**
     * Show all branches for a module's GitHub repository
     */
    public function ___executeBranches(): string {
        $id = (int)$this->wire('input')->get('id');
        $repo = $this->getRepoById($id);

        if (!$repo) {
            $this->error($this->_('Module mapping not found.'));
            $this->wire('session')->redirect('../');
            return '';
        }

        $this->headline(sprintf($this->_('Branches: %s'), $repo['module_class']));
        $this->breadcrumb('../', $this->_('GitSync'));

        try {
            $branches = $this->getGitHub()->listBranches($repo['owner'], $repo['repo'], true);
        } catch (GitSyncException $e) {
            $this->error($e->getMessage());
            return '<p>' . $this->_('Could not load branches from GitHub.') . '</p>';
        }

        $modules = $this->wire('modules');
        $csrfName = $this->wire('session')->CSRF->getTokenName();
        $csrfValue = $this->wire('session')->CSRF->getTokenValue();

        /** @var MarkupAdminDataTable $table */
        $table = $modules->get('MarkupAdminDataTable');
        $table->setEncodeEntities(false);
        $table->headerRow([
            $this->_('Branch'),
            $this->_('Last Commit'),
            $this->_('Date'),
            $this->_('Status'),
            $this->_('Action'),
        ]);

        foreach ($branches as $branch) {
            $isCurrent = ($branch['name'] === $repo['current_branch']);
            $shortSha = substr($branch['sha'], 0, 7);

            // Determine status
            if ($isCurrent) {
                if ($branch['sha'] === $repo['last_commit_sha']) {
                    $status = '<span class="pw-badge" style="background:#4CAF50;color:#fff;padding:2px 8px">' . $this->_('up to date') . '</span>';
                } else {
                    $status = '<span class="pw-badge" style="background:#FF9800;color:#fff;padding:2px 8px">' . $this->_('updates available') . '</span>';
                }
            } else {
                $status = '';
            }

            // Format date
            $dateStr = '';
            if (!empty($branch['date'])) {
                $timestamp = strtotime($branch['date']);
                $dateStr = wireDate('Y.m.d H:i', $timestamp);
            }

            // Sync button – show webhook badge instead for the active webhook branch
            $syncBtn = '';
            if ($isCurrent && !empty($repo['webhook_active'])) {
                $syncBtn = '<span class="pw-badge" style="background:#1565C0;color:#fff;padding:2px 8px"><i class="fa fa-bolt"></i> webhook</span>';
            } else {
                $formId = 'gitsync-sync-' . $id . '-' . $this->wire('sanitizer')->pageName($branch['name']);
                $syncBtn = sprintf(
                    '<form method="post" action="../sync/" style="display:none" id="%s">' .
                    '<input type="hidden" name="%s" value="%s">' .
                    '<input type="hidden" name="id" value="%d">' .
                    '<input type="hidden" name="branch" value="%s">' .
                    '</form>' .
                    '<a href="#" onclick="if(confirm(\'%s\')){document.getElementById(\'%s\').submit()};return false">' .
                    '<i class="fa fa-download"></i> %s</a>',
                    $formId,
                    $this->wire('sanitizer')->entities($csrfName),
                    $this->wire('sanitizer')->entities($csrfValue),
                    $id,
                    $this->wire('sanitizer')->entities($branch['name']),
                    sprintf($this->_('Sync branch "%s" to module "%s"?'), $branch['name'], $repo['module_class']),
                    $formId,
                    $this->_('Sync')
                );
            }

            $branchLabel = $this->wire('sanitizer')->entities($branch['name']);
            if ($isCurrent) {
                $branchLabel = "<strong>{$branchLabel}</strong> <i class='fa fa-check'></i>";
            }

            $commitUrl = "https://github.com/{$repo['owner']}/{$repo['repo']}/commit/{$branch['sha']}";

            $table->row([
                $branchLabel,
                "<a href='{$commitUrl}' target='_blank' title='{$branch['sha']}'><code>{$shortSha}</code></a>",
                $dateStr,
                $status,
                $syncBtn,
            ]);
        }

        $out = $table->render();

        // Rate limit info
        $rateLimit = $this->getGitHub()->getRateLimit();
        if ($rateLimit['remaining'] !== null) {
            $out .= "<p class='uk-text-small uk-text-muted'>" . sprintf(
                $this->_('GitHub API: %d requests remaining'),
                $rateLimit['remaining']
            ) . "</p>";
        }

        return $out;
    }

    // =========================================================================
    // Admin Page: Sync (POST only)
    // =========================================================================

    /**
     * Sync a branch to a module's directory (admin POST handler)
     */
    public function ___executeSync(): string {
        // Only accept POST
        if (empty($_POST['id']) && empty($_POST['branch'])) {
            $this->wire('session')->redirect('../');
            return '';
        }

        $this->wire('session')->CSRF->validate();

        $id = (int)$_POST['id'];
        $branch = $this->sanitizeBranchName($_POST['branch'] ?? '');
        $repo = $this->getRepoById($id);

        if (!$repo) {
            $this->error($this->_('Module mapping not found.'));
            $this->wire('session')->redirect('../');
            return '';
        }

        if (empty($branch)) {
            $this->error($this->_('No branch specified.'));
            $this->wire('session')->redirect("../branches/?id={$id}");
            return '';
        }

        if ($repo['module_class'] === 'GitSync') {
            $this->warning($this->_('Self-update: GitSync will be updated. You may need to reload the page after sync.'));
        }

        try {
            $result = $this->performSync($id, $repo, $branch, 'manual');

            if ($result['status'] === 'up_to_date') {
                $this->message(sprintf(
                    $this->_('"%s" is already up to date on branch "%s".'),
                    $repo['module_class'], $branch
                ));
            } else {
                $this->message(sprintf(
                    $this->_('Synced "%s" to branch "%s" (commit %s) – %d file(s) updated, %d file(s) deleted.'),
                    $repo['module_class'], $branch, $result['sha'], $result['updated'], $result['deleted']
                ));
            }
        } catch (\Throwable $e) {
            $this->error($this->_('Sync failed: ') . $e->getMessage());
            $this->wire('log')->save('gitsync', "Sync FAILED for {$repo['module_class']}: " . $e->getMessage());
        }

        $this->wire('session')->redirect("../branches/?id={$id}");
        return '';
    }


    // =========================================================================
    // Core Sync Logic (shared by manual sync and webhook)
    // =========================================================================

    /**
     * Perform a differential sync of a GitHub branch to a module directory.
     *
     * @param int $id Repo mapping index
     * @param array $repo Repo mapping data
     * @param string $branch Branch name to sync
     * @param string $source Label for log messages ('manual', 'auto-sync', 'webhook')
     * @return array ['status' => 'synced'|'up_to_date', 'updated' => int, 'deleted' => int, 'sha' => string]
     * @throws GitSyncException
     */
    public function performSync(int $id, array $repo, string $branch, string $source = 'manual'): array {
        $moduleClass = $repo['module_class'];
        $targetDir = $this->wire('config')->paths->siteModules . $moduleClass . '/';

        // 1. Check write permissions
        $this->checkWritePermissions($targetDir);

        // 2. Get branch info and remote file tree (single API call for branch, reused by getTree)
        $github = $this->getGitHub();
        $branchData = $github->getBranchRaw($repo['owner'], $repo['repo'], $branch);
        $branchInfo = [
            'name' => $branchData['name'],
            'sha' => $branchData['commit']['sha'],
        ];
        $remoteTree = $github->getTree($repo['owner'], $repo['repo'], $branch, $branchData);

        // 3. Build map of remote files: path => sha
        $remoteFiles = [];
        foreach ($remoteTree as $entry) {
            if ($entry['type'] === 'blob') {
                if (strpos($entry['path'], '..') !== false || $entry['path'][0] === '/') {
                    throw new GitSyncException("Suspicious path in remote tree: {$entry['path']}");
                }
                $remoteFiles[$entry['path']] = $entry['sha'];
            }
        }

        // 4. Build map of local files: path => git blob sha
        $localFiles = is_dir($targetDir) ? $this->buildLocalFileMap($targetDir) : [];

        // 5. Determine changes
        $toUpdate = [];
        $toDelete = [];

        foreach ($remoteFiles as $path => $sha) {
            if (!isset($localFiles[$path]) || $localFiles[$path] !== $sha) {
                $toUpdate[$path] = $sha;
            }
        }

        foreach ($localFiles as $path => $sha) {
            if (!isset($remoteFiles[$path])) {
                $toDelete[] = $path;
            }
        }

        $this->wire('log')->save('gitsync', sprintf(
            '[%s] Sync "%s" branch "%s": %d remote, %d local, %d to update, %d to delete',
            $source, $moduleClass, $branch, count($remoteFiles), count($localFiles),
            count($toUpdate), count($toDelete)
        ));

        // 6. Nothing to do?
        if (empty($toUpdate) && empty($toDelete)) {
            $repos = $this->getModuleRepos();
            $repos[$id]['current_branch'] = $branch;
            $repos[$id]['last_synced'] = date('c');
            $repos[$id]['last_commit_sha'] = $branchInfo['sha'];
            if ($source === 'webhook') $repos[$id]['webhook_active'] = true;
            $this->saveModuleRepos($repos);

            return ['status' => 'up_to_date', 'updated' => 0, 'deleted' => 0, 'sha' => substr($branchInfo['sha'], 0, 7)];
        }

        // 7. Ensure target directory exists
        if (!is_dir($targetDir)) {
            $this->wire('files')->mkdir($targetDir, true);
        }

        // 8. Download changed files and write them
        $updated = 0;
        foreach ($toUpdate as $path => $sha) {
            $filePath = $targetDir . $path;
            $fileDir = dirname($filePath);

            if (!is_dir($fileDir)) {
                $this->wire('files')->mkdir($fileDir, true);
            }

            $content = $this->getGitHub()->downloadBlob($repo['owner'], $repo['repo'], $sha);

            if (file_put_contents($filePath, $content) === false) {
                throw new GitSyncException("Cannot write file: {$path} – check directory permissions (need 770)");
            }

            $this->wire('log')->save('gitsync', sprintf('  Updated: %s (%d bytes)', $path, strlen($content)));
            $updated++;
        }

        // 9. Delete files that no longer exist remotely
        $deleted = 0;
        foreach ($toDelete as $path) {
            $filePath = $targetDir . $path;
            if (is_file($filePath)) {
                if (unlink($filePath)) {
                    $deleted++;
                } else {
                    $this->wire('log')->save('gitsync', "  FAILED to delete: {$path}");
                }
            }
        }

        // 10. Clean up empty directories
        if ($deleted > 0) {
            $this->removeEmptyDirectories($targetDir);
        }

        // 11. Update repo mapping
        $repos = $this->getModuleRepos();
        $repos[$id]['current_branch'] = $branch;
        $repos[$id]['last_synced'] = date('c');
        $repos[$id]['last_commit_sha'] = $branchInfo['sha'];
        if ($source === 'webhook') $repos[$id]['webhook_active'] = true;
        $this->saveModuleRepos($repos);

        // 12. Refresh PW modules cache
        $this->wire('modules')->refresh();

        // 13. Log
        $this->wire('log')->save('gitsync', sprintf(
            '[%s] Synced "%s" to branch "%s" (commit %s) – %d updated, %d deleted',
            $source, $moduleClass, $branch, substr($branchInfo['sha'], 0, 7), $updated, $deleted
        ));

        return [
            'status' => 'synced',
            'updated' => $updated,
            'deleted' => $deleted,
            'sha' => substr($branchInfo['sha'], 0, 7),
        ];
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Build a map of local files with their git blob SHAs
     *
     * Git blob SHA = sha1("blob {size}\0{content}")
     * This matches the SHA returned by GitHub's Tree API,
     * so we can compare directly without downloading.
     *
     * @param string $dir Module directory (absolute path with trailing slash)
     * @return array ['relative/path' => 'sha1hash', ...]
     */
    protected function buildLocalFileMap(string $dir): array {
        $map = [];
        $dir = rtrim($dir, '/') . '/';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;

            $fullPath = $file->getPathname();
            $relativePath = substr($fullPath, strlen($dir));

            // Compute git blob SHA: sha1("blob {filesize}\0{content}")
            $content = file_get_contents($fullPath);
            $blob = "blob " . strlen($content) . "\0" . $content;
            $map[$relativePath] = sha1($blob);
        }

        return $map;
    }

    /**
     * Recursively remove empty directories within a path
     */
    protected function removeEmptyDirectories(string $dir): void {
        $dir = rtrim($dir, '/');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                $path = $item->getPathname();
                // Only remove if empty (scandir returns just . and ..)
                if (count(scandir($path)) === 2) {
                    @rmdir($path);
                }
            }
        }
    }

    /**
     * Get the current PHP process user name
     */
    protected function getSystemUser(): string {
        // Try multiple methods – posix functions are often disabled on shared hosting
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if ($info && !empty($info['name'])) {
                return $info['name'];
            }
        }
        // get_current_user() returns the owner of the current PHP SCRIPT file,
        // not the process user – but it's better than nothing
        $scriptOwner = get_current_user();
        if ($scriptOwner) return $scriptOwner;
        // Last resort: try whoami via shell
        $whoami = @exec('whoami 2>/dev/null');
        if ($whoami) return trim($whoami);
        return (string)(function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
    }

    /**
     * Get the owner name of a file/directory
     */
    protected function getFileOwner(string $path): string {
        if (!file_exists($path)) return '?';
        $uid = fileowner($path);
        if ($uid === false) return '?';
        if (function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid($uid);
            if ($info && !empty($info['name'])) {
                return $info['name'];
            }
        }
        return (string)$uid;
    }

    /**
     * Check if PHP can write to the site/modules/ directory
     */
    protected function isSiteModulesWritable(): bool {
        $modulesDir = $this->wire('config')->paths->siteModules;
        $testFile = $modulesDir . '.gitsync_perm_test_' . mt_rand();
        $canWrite = @file_put_contents($testFile, 'test');
        if ($canWrite !== false) {
            @unlink($testFile);
            return true;
        }
        return false;
    }

    /**
     * Parse a GitHub URL into owner and repo components
     *
     * @return array|null ['owner' => string, 'repo' => string] or null
     */
    protected function parseGitHubUrl(string $url): ?array {
        if (preg_match('~github\.com/([^/]+)/([^/\s?#]+)~i', $url, $m)) {
            return [
                'owner' => $m[1],
                'repo' => rtrim($m[2], '.git'),
            ];
        }
        return null;
    }

    /**
     * Sanitize a git branch name – allows a-z, 0-9, /, -, _, .
     */
    protected function sanitizeBranchName($name): string {
        $name = (string)$name;
        return preg_replace('/[^a-zA-Z0-9\/\-_.]/', '', $name);
    }

    /**
     * Check if PHP can write to a module directory.
     * Throws a clear error message with exact instructions if not.
     */
    protected function checkWritePermissions(string $targetDir): void {
        // Test 1: Can PHP write to the module directory?
        if (is_dir($targetDir)) {
            $testFile = $targetDir . '.gitsync_write_test_' . mt_rand();
            $result = @file_put_contents($testFile, 'test');
            if ($result !== false) {
                @unlink($testFile);
                return; // All good
            }
        } else {
            // Directory doesn't exist yet – check if we can create it
            if (@mkdir($targetDir, 0770, true)) {
                return; // Created successfully
            }
        }

        // Write failed – build helpful error message
        $checkDir = is_dir($targetDir) ? $targetDir : $this->wire('config')->paths->siteModules;
        $dirPerms = is_dir($checkDir) ? substr(sprintf('%o', fileperms($checkDir)), -4) : 'n/a';
        $phpUser = $this->getSystemUser();
        $dirOwner = $this->getFileOwner($checkDir);

        throw new GitSyncException(sprintf(
            $this->_(
                'GitSync cannot write to the module directory (permissions: %1$s, owner: %2$s, PHP user: %3$s). '
                . 'Set permissions to 770 on this folder via FTP (right-click → Properties → 770 → apply recursively): %4$s'
            ),
            $dirPerms, $dirOwner, $phpUser,
            is_dir($targetDir) ? $targetDir : $checkDir
        ));
    }

    /**
     * Module install – check permissions and notify the user
     */
    public function ___install() {
        parent::___install();

        if ($this->isSiteModulesWritable()) return;

        $modulesDir = $this->wire('config')->paths->siteModules;
        $this->warning(sprintf(
            $this->_(
                'GitSync needs write access to site/modules/. Currently: owner="%1$s", permissions=%2$s, PHP user="%3$s". '
                . 'Please set permissions to 770 on the module directories you want to sync. '
                . 'In your FTP client: right-click the module folder → Properties → set to 770 → apply recursively.'
            ),
            $this->getFileOwner($modulesDir),
            substr(sprintf('%o', fileperms($modulesDir)), -4),
            $this->getSystemUser()
        ), Notice::allowMarkup);
    }

    /**
     * Module uninstall - cleanup
     */
    public function ___uninstall() {
        // Clean up cache directory
        $cacheDir = $this->wire('config')->paths->assets . 'cache/GitSync/';
        if (is_dir($cacheDir)) {
            $this->wire('files')->rmdir($cacheDir, true);
        }
        parent::___uninstall();
    }
}

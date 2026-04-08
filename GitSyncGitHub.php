<?php

namespace ProcessWire;

/**
 * GitHub API client for GitSync module.
 * Handles all communication with the GitHub REST API.
 */

class GitSyncException extends \Exception {}

class GitSyncGitHub {

    /** @var string GitHub personal access token */
    protected $token = '';

    /** @var int Remaining API rate limit */
    protected $rateLimitRemaining = null;

    /** @var int Unix timestamp when rate limit resets */
    protected $rateLimitReset = null;

    /**
     * @param string $token GitHub personal access token (optional, required for private repos)
     */
    public function __construct(string $token = '') {
        $this->token = $token;
    }

    // =========================================================================
    // Shared HTTP helpers
    // =========================================================================

    /**
     * Build HTTP headers for API requests
     */
    protected function buildHeaders(): array {
        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ];

        if ($this->token) {
            $headers[] = "Authorization: Bearer {$this->token}";
        }

        return $headers;
    }

    /**
     * Parse rate-limit values from a single HTTP response header line
     */
    protected function parseRateLimitHeader(string $header): void {
        $parts = explode(':', $header, 2);
        if (count($parts) !== 2) return;

        $name = strtolower(trim($parts[0]));
        $value = trim($parts[1]);

        if ($name === 'x-ratelimit-remaining') {
            $this->rateLimitRemaining = (int)$value;
        } elseif ($name === 'x-ratelimit-reset') {
            $this->rateLimitReset = (int)$value;
        }
    }

    /**
     * Create a cURL handle with shared defaults (rate-limit header parsing, timeouts, user-agent)
     *
     * @param string $url Request URL
     * @param array $headers Custom headers (default: buildHeaders())
     * @param array $extraOpts Additional CURLOPT_* overrides
     */
    protected function buildCurlHandle(string $url, array $headers = [], array $extraOpts = []) {
        $ch = curl_init();

        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'GitSync-ProcessWire/0.1',
            CURLOPT_HTTPHEADER => $headers ?: $this->buildHeaders(),
            CURLOPT_HEADERFUNCTION => function ($ch, $header) {
                $this->parseRateLimitHeader($header);
                return strlen($header);
            },
        ];

        foreach ($extraOpts as $k => $v) {
            $opts[$k] = $v;
        }

        curl_setopt_array($ch, $opts);
        return $ch;
    }

    // =========================================================================
    // Core API methods
    // =========================================================================

    /**
     * Make an API request to GitHub
     *
     * @param string $url Full API URL
     * @return array Decoded JSON response
     * @throws GitSyncException
     */
    protected function apiRequest(string $url): array {
        $ch = $this->buildCurlHandle($url);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new GitSyncException("GitHub API request failed: {$error}");
        }

        $data = json_decode($response, true);

        if ($httpCode === 404) {
            throw new GitSyncException("Repository not found or not accessible. Check the owner/repo name and your access token.");
        }

        if ($httpCode === 403 && $this->rateLimitRemaining === 0) {
            $resetTime = date('H:i:s', $this->rateLimitReset);
            throw new GitSyncException("GitHub API rate limit exceeded. Resets at {$resetTime}. Add a GitHub token in the module settings to increase the limit.");
        }

        if ($httpCode === 401) {
            throw new GitSyncException("GitHub authentication failed. Check your access token in the module settings.");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = $data['message'] ?? 'Unknown error';
            throw new GitSyncException("GitHub API error (HTTP {$httpCode}): {$message}");
        }

        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new GitSyncException("Failed to parse GitHub API response: " . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Make an API request and return both HTTP status and data (does not throw on 404)
     *
     * @param string $url Full API URL
     * @return array ['status' => int, 'data' => array|null]
     */
    public function apiRequestRaw(string $url): array {
        $ch = $this->buildCurlHandle($url);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => (int) $httpCode,
            'data' => ($response !== false) ? json_decode($response, true) : null,
        ];
    }

    // =========================================================================
    // Repository & Branch operations
    // =========================================================================

    /**
     * Verify that a repository exists and is accessible
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @return array Repository info
     * @throws GitSyncException
     */
    public function getRepository(string $owner, string $repo): array {
        return $this->apiRequest("https://api.github.com/repos/{$owner}/{$repo}");
    }

    /**
     * List all branches for a repository
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param bool $withDates Fetch commit dates (costs one API call per branch)
     * @return array Array of ['name' => string, 'sha' => string, 'date' => string]
     * @throws GitSyncException
     */
    public function listBranches(string $owner, string $repo, bool $withDates = false): array {
        $branches = [];
        $page = 1;
        $perPage = 100;

        do {
            $url = "https://api.github.com/repos/{$owner}/{$repo}/branches?per_page={$perPage}&page={$page}";
            $response = $this->apiRequest($url);

            foreach ($response as $branch) {
                $entry = [
                    'name' => $branch['name'],
                    'sha' => $branch['commit']['sha'],
                    'date' => '',
                ];

                if ($withDates) {
                    $commitUrl = "https://api.github.com/repos/{$owner}/{$repo}/commits/" . $branch['commit']['sha'];
                    try {
                        $commitData = $this->apiRequest($commitUrl);
                        $entry['date'] = $commitData['commit']['committer']['date'] ?? '';
                    } catch (\Exception $e) {
                        // Ignore – date stays empty
                    }
                }

                $branches[] = $entry;
            }

            $page++;
        } while (count($response) === $perPage);

        if ($withDates) {
            usort($branches, function ($a, $b) {
                return strcmp($b['date'], $a['date']);
            });
        }

        return $branches;
    }

    /**
     * Get details for a specific branch
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param string $branch Branch name
     * @return array Branch details including latest commit
     * @throws GitSyncException
     */
    public function getBranch(string $owner, string $repo, string $branch): array {
        $response = $this->getBranchRaw($owner, $repo, $branch);

        return [
            'name' => $response['name'],
            'sha' => $response['commit']['sha'],
            'commit_message' => $response['commit']['commit']['message'] ?? '',
            'commit_date' => $response['commit']['commit']['committer']['date'] ?? '',
            'commit_author' => $response['commit']['commit']['author']['name'] ?? '',
        ];
    }

    /**
     * Get raw branch API response (includes tree SHA needed by getTree)
     */
    public function getBranchRaw(string $owner, string $repo, string $branch): array {
        $url = "https://api.github.com/repos/{$owner}/{$repo}/branches/" . urlencode($branch);
        return $this->apiRequest($url);
    }

    // =========================================================================
    // File tree & blob operations
    // =========================================================================

    /**
     * Get the full file tree for a branch (recursive)
     *
     * Returns all files with their paths and git blob SHAs.
     * The blob SHA is a content hash – if it matches, the file is identical.
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param string $branch Branch name
     * @param array $branchData Pre-fetched branch data (optional, saves one API call)
     * @return array Array of ['path' => string, 'sha' => string, 'size' => int, 'type' => 'blob'|'tree']
     * @throws GitSyncException
     */
    public function getTree(string $owner, string $repo, string $branch, array $branchData = []): array {
        if (empty($branchData)) {
            $branchData = $this->apiRequest(
                "https://api.github.com/repos/{$owner}/{$repo}/branches/" . urlencode($branch)
            );
        }
        $treeSha = $branchData['commit']['commit']['tree']['sha'];

        $url = "https://api.github.com/repos/{$owner}/{$repo}/git/trees/{$treeSha}?recursive=1";
        $response = $this->apiRequest($url);

        if (!empty($response['truncated'])) {
            throw new GitSyncException("Repository tree is too large for differential sync. Use full sync instead.");
        }

        $files = [];
        foreach ($response['tree'] as $entry) {
            $files[] = [
                'path' => $entry['path'],
                'sha' => $entry['sha'],
                'size' => $entry['size'] ?? 0,
                'type' => $entry['type'],
            ];
        }

        return $files;
    }

    /**
     * Download a single file's content by its blob SHA
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param string $sha Blob SHA
     * @return string Raw file content
     * @throws GitSyncException
     */
    public function downloadBlob(string $owner, string $repo, string $sha): string {
        $url = "https://api.github.com/repos/{$owner}/{$repo}/git/blobs/{$sha}";

        // Custom headers – we need raw content instead of JSON
        $headers = [
            'Accept: application/vnd.github.raw+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        if ($this->token) {
            $headers[] = "Authorization: Bearer {$this->token}";
        }

        $ch = $this->buildCurlHandle($url, $headers);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new GitSyncException("Failed to download blob {$sha}: {$error}");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new GitSyncException("GitHub returned HTTP {$httpCode} when downloading blob {$sha}");
        }

        return $response;
    }

    // =========================================================================
    // Repository discovery & resolution
    // =========================================================================

    /**
     * Search the authenticated user's repo list for a specific repo.
     * Used by findRepoViaAuth() and resolveDefaultBranch().
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param int $maxPages Maximum pages to scan
     * @return array|null Raw repo item from GitHub API, or null if not found
     */
    protected function searchAuthenticatedRepos(string $owner, string $repo, int $maxPages = 10): ?array {
        $page = 1;
        while ($page <= $maxPages) {
            $r = $this->apiRequestRaw(
                "https://api.github.com/user/repos?per_page=100&page={$page}&sort=updated&type=all"
            );
            if ($r['status'] !== 200 || empty($r['data'])) break;

            foreach ($r['data'] as $item) {
                if (strcasecmp($item['full_name'] ?? '', "{$owner}/{$repo}") === 0) {
                    return $item;
                }
            }

            if (count($r['data']) < 100) break;
            $page++;
        }
        return null;
    }

    /**
     * Find a repo via the authenticated user's repo list (/user/repos).
     * This endpoint returns ALL repos the token can access, including private ones,
     * even when /repos/{owner}/{repo} returns 404.
     *
     * @return array|null Repo data with 'default_branch', 'full_name', etc. or null
     */
    public function findRepoViaAuth(string $owner, string $repo): ?array {
        $item = $this->searchAuthenticatedRepos($owner, $repo);
        if (!$item) return null;

        return [
            'full_name' => $item['full_name'],
            'owner' => $item['owner']['login'] ?? $owner,
            'repo' => $item['name'] ?? $repo,
            'default_branch' => $item['default_branch'] ?? 'main',
            'private' => $item['private'] ?? false,
            'clone_url' => $item['clone_url'] ?? '',
            'description' => $item['description'] ?? '',
        ];
    }

    /**
     * Resolve the default branch for a repo.
     *
     * Tries multiple strategies since fine-grained tokens may 404 on
     * /repos/{owner}/{repo} endpoints.
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @return string Branch name (e.g. 'main' or 'master')
     * @throws GitSyncException when the repo is truly unreachable
     */
    public function resolveDefaultBranch(string $owner, string $repo): string {
        // 1. Fast path: getRepository
        try {
            $info = $this->getRepository($owner, $repo);
            return $info['default_branch'] ?? 'main';
        } catch (\Throwable $e) {}

        // 2. Probe branches endpoint
        foreach (['main', 'master'] as $candidate) {
            try {
                $this->apiRequest(
                    "https://api.github.com/repos/{$owner}/{$repo}/branches/" . urlencode($candidate)
                );
                return $candidate;
            } catch (\Throwable $ignored) {}
        }

        // 3. List all branches
        try {
            $branches = $this->listBranches($owner, $repo);
            if (!empty($branches)) {
                return $branches[0]['name'];
            }
        } catch (\Throwable $ignored) {}

        // 4. Find via /user/repos (authenticated user's full repo list)
        $found = $this->findRepoViaAuth($owner, $repo);
        if ($found) {
            return $found['default_branch'];
        }

        // 5. Find via /users/ or /orgs/ listing
        try {
            foreach ($this->listUserRepos($owner, 200) as $r) {
                if (strcasecmp($r['repo'], $repo) === 0) {
                    return $r['default_branch'] ?? 'main';
                }
            }
        } catch (\Throwable $ignored) {}

        throw new GitSyncException(
            "Cannot access repository {$owner}/{$repo}. "
            . "The token does not appear to have access to this repository."
        );
    }

    /**
     * Check whether a repository is accessible (without throwing)
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @return bool
     */
    public function isRepoAccessible(string $owner, string $repo): bool {
        try {
            $this->getRepository($owner, $repo);
            return true;
        } catch (\Throwable $e) {}

        foreach (['main', 'master'] as $branch) {
            try {
                $this->apiRequest(
                    "https://api.github.com/repos/{$owner}/{$repo}/branches/" . urlencode($branch)
                );
                return true;
            } catch (\Throwable $e) {}
        }

        return false;
    }

    /**
     * Get the current rate limit status
     *
     * @return array ['remaining' => int, 'reset' => int]
     */
    public function getRateLimit(): array {
        return [
            'remaining' => $this->rateLimitRemaining,
            'reset' => $this->rateLimitReset,
        ];
    }

    // =========================================================================
    // Search & discovery
    // =========================================================================

    /**
     * Search for repositories by name
     *
     * @param string $query Search query (e.g. module class name)
     * @param int $limit Max results
     * @return array Array of ['full_name' => 'owner/repo', 'description' => string, 'url' => string]
     * @throws GitSyncException
     */
    public function searchRepositories(string $query, int $limit = 10): array {
        $url = 'https://api.github.com/search/repositories?q=' . urlencode($query) . '&per_page=' . $limit;
        $response = $this->apiRequest($url);

        $results = [];
        foreach ($response['items'] ?? [] as $item) {
            $results[] = [
                'full_name' => $item['full_name'],
                'owner' => $item['owner']['login'] ?? '',
                'repo' => $item['name'] ?? '',
                'description' => $item['description'] ?? '',
                'url' => $item['html_url'] ?? '',
            ];
        }

        return $results;
    }

    /**
     * List repositories of a user or organization
     *
     * @param string $username GitHub username or organization
     * @param int $limit Max results
     * @return array Same format as searchRepositories()
     * @throws GitSyncException
     */
    public function listUserRepos(string $username, int $limit = 30): array {
        $endpoints = [
            "https://api.github.com/users/{$username}/repos?per_page={$limit}&sort=updated",
            "https://api.github.com/orgs/{$username}/repos?per_page={$limit}&sort=updated&type=all",
        ];

        $results = [];
        foreach ($endpoints as $url) {
            try {
                $response = $this->apiRequest($url);
                foreach ($response as $item) {
                    $fullName = $item['full_name'] ?? '';
                    if (empty($fullName) || isset($results[$fullName])) continue;
                    $results[$fullName] = [
                        'full_name' => $fullName,
                        'owner' => $item['owner']['login'] ?? '',
                        'repo' => $item['name'] ?? '',
                        'description' => $item['description'] ?? '',
                        'url' => $item['html_url'] ?? '',
                        'default_branch' => $item['default_branch'] ?? 'main',
                        'private' => $item['private'] ?? false,
                    ];
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return array_values($results);
    }

    /**
     * Check if a repository contains a ProcessWire module (.module.php file in root)
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param string $branch Branch name (default: repo default branch)
     * @return string|null Module class name if found, null otherwise
     * @throws GitSyncException
     */
    public function detectModuleClass(string $owner, string $repo, string $branch = ''): ?string {
        if (empty($branch)) {
            $branch = $this->resolveDefaultBranch($owner, $repo);
        }

        $branchData = $this->apiRequest(
            "https://api.github.com/repos/{$owner}/{$repo}/branches/" . urlencode($branch)
        );
        $treeSha = $branchData['commit']['commit']['tree']['sha'];

        // Get only the root level tree (not recursive)
        $url = "https://api.github.com/repos/{$owner}/{$repo}/git/trees/{$treeSha}";
        $response = $this->apiRequest($url);

        foreach ($response['tree'] as $entry) {
            if ($entry['type'] === 'blob' && preg_match('/^(\w+)\.module(?:\.php)?$/', $entry['path'], $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Find which repo of a given user contains a specific module class (by filename)
     *
     * Uses the Code Search API — single call instead of scanning each repo's tree.
     *
     * @param string $owner GitHub username or organization
     * @param string $moduleClass Module class name (e.g. "ThreeColoredButtons")
     * @return array|null Repo info ['full_name', 'owner', 'repo', 'description', 'url'] or null
     */
    public function findRepoByModuleClass(string $owner, string $moduleClass): ?array {
        $q = "filename:{$moduleClass}.module user:{$owner}";
        $url = 'https://api.github.com/search/code?q=' . urlencode($q) . '&per_page=5';
        $response = $this->apiRequest($url);

        foreach ($response['items'] ?? [] as $item) {
            $name = $item['name'] ?? '';
            if ($name !== "{$moduleClass}.module" && $name !== "{$moduleClass}.module.php") {
                continue;
            }
            $repo = $item['repository'] ?? [];
            if (!empty($repo['full_name'])) {
                return [
                    'full_name' => $repo['full_name'],
                    'owner' => $repo['owner']['login'] ?? $owner,
                    'repo' => $repo['name'] ?? '',
                    'description' => $repo['description'] ?? '',
                    'url' => $repo['html_url'] ?? '',
                ];
            }
        }

        return null;
    }

    /**
     * Public Code Search for a PW module by its exact filename.
     *
     * Finds repos where the repo name differs from the module class name
     * (e.g. repo "VideoOrSocialPostEmbed" containing
     * "TextformatterVideoOrSocialPostEmbed.module").
     *
     * @param string $moduleClass Module class name
     * @param int $limit Max results from API
     * @return array Array of repo info arrays
     * @throws GitSyncException
     */
    public function findPublicReposByModuleClass(string $moduleClass, int $limit = 5): array {
        $q = "filename:{$moduleClass}.module";
        $url = 'https://api.github.com/search/code?q=' . urlencode($q) . '&per_page=' . $limit;
        $response = $this->apiRequest($url);

        $results = [];
        $seen = [];
        foreach ($response['items'] ?? [] as $item) {
            $name = $item['name'] ?? '';
            if ($name !== "{$moduleClass}.module" && $name !== "{$moduleClass}.module.php") {
                continue;
            }
            $repo = $item['repository'] ?? [];
            $fullName = $repo['full_name'] ?? '';
            if (!empty($fullName) && !isset($seen[$fullName])) {
                $seen[$fullName] = true;
                $results[] = [
                    'full_name' => $fullName,
                    'owner' => $repo['owner']['login'] ?? '',
                    'repo' => $repo['name'] ?? '',
                    'description' => $repo['description'] ?? '',
                    'url' => $repo['html_url'] ?? '',
                ];
            }
        }

        return $results;
    }

    /**
     * Check whether a repository contains a ProcessWire module file.
     *
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param string $moduleClass Module class name
     * @return bool
     */
    public function repoHasModuleFile(string $owner, string $repo, string $moduleClass): bool {
        $url = "https://api.github.com/repos/{$owner}/{$repo}/contents/{$moduleClass}.module.php";
        $result = $this->apiRequestRaw($url);
        if ($result['status'] === 200) return true;

        $url = "https://api.github.com/repos/{$owner}/{$repo}/contents/{$moduleClass}.module";
        $result = $this->apiRequestRaw($url);
        return $result['status'] === 200;
    }

    /**
     * Find all PW modules in a user's repositories (by .module.php filenames)
     *
     * Uses the Code Search API — single call instead of scanning each repo's tree.
     *
     * @param string $owner GitHub username or organization
     * @return array Array of ['full_name', 'owner', 'repo', 'description', 'url', 'module_class']
     */
    public function findUserModules(string $owner): array {
        $q = "filename:.module user:{$owner}";
        $url = 'https://api.github.com/search/code?q=' . urlencode($q) . '&per_page=100';
        $response = $this->apiRequest($url);

        $results = [];
        $seen = [];
        foreach ($response['items'] ?? [] as $item) {
            if (preg_match('/^(\w+)\.module(?:\.php)?$/', $item['name'] ?? '', $m)) {
                $moduleClass = $m[1];
                if (isset($seen[$moduleClass])) continue;
                $seen[$moduleClass] = true;

                $repo = $item['repository'] ?? [];
                $results[] = [
                    'full_name' => $repo['full_name'] ?? '',
                    'owner' => $repo['owner']['login'] ?? $owner,
                    'repo' => $repo['name'] ?? '',
                    'description' => $repo['description'] ?? '',
                    'url' => $repo['html_url'] ?? '',
                    'module_class' => $moduleClass,
                ];
            }
        }

        return $results;
    }
}

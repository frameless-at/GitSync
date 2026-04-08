<?php

namespace ProcessWire;

/**
 * Configuration for the GitSync module
 */
class GitSyncConfig extends ModuleConfig {

    public function getDefaults(): array {
        return [
            'github_token' => '',
            'webhook_secret' => '',
            'module_repos' => '[]',
        ];
    }

    public function getInputfields(): InputfieldWrapper {
        $inputfields = parent::getInputfields();

        /** @var InputfieldText $f */
        $f = $this->wire('modules')->get('InputfieldText');
        $f->attr('name', 'github_token');
        $f->label = $this->_('GitHub Personal Access Token');
        $f->description = $this->_('Required for private repositories and higher API rate limits (5000/hour vs 60/hour).');
        $f->notes = $this->_('Create a fine-grained token at GitHub > Settings > Developer settings > Personal access tokens. Grant read-only access to "Contents" for the needed repositories.');
        $f->attr('type', 'password');
        $f->attr('autocomplete', 'off');
        $f->columnWidth = 100;
        $inputfields->add($f);

        /** @var InputfieldText $f */
        $f = $this->wire('modules')->get('InputfieldText');
        $f->attr('name', 'webhook_secret');
        $f->label = $this->_('Webhook Secret');
        $f->description = $this->_('Enables automatic sync on every git push. Enter a random string here and use the same string as "Secret" in the GitHub webhook settings. Leave empty to disable.');
        $f->notes = $this->_('Tip: Generate a random string with `openssl rand -hex 32` in your terminal.');
        $f->columnWidth = 100;
        $inputfields->add($f);

        // Webhook URL (always shown – the URL is static, only the secret enables verification)
        $fullUrl = rtrim($this->wire('config')->urls->httpRoot, '/') . '/gitsync-webhook/';

        /** @var InputfieldMarkup $f */
        $f = $this->wire('modules')->get('InputfieldMarkup');
        $f->label = $this->_('Webhook URL');
        $f->description = $this->_('Add this URL as a webhook in your GitHub repository settings (Settings → Webhooks → Add webhook).');
        $f->value = '<code style="font-size:14px;padding:8px 12px;background:#f5f5f5;border:1px solid #ddd;display:inline-block;border-radius:3px">'
            . $this->wire('sanitizer')->entities($fullUrl)
            . '</code>'
            . '<br><br><small>' . $this->_('Content type: application/json — Events: Just the push event') . '</small>';
        $f->columnWidth = 100;
        $inputfields->add($f);

        /** @var InputfieldHidden $f */
        $f = $this->wire('modules')->get('InputfieldHidden');
        $f->attr('name', 'module_repos');
        $inputfields->add($f);

        return $inputfields;
    }
}

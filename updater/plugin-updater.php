<?php

if (!class_exists('FWCH_Plugin_Updater')) {

    class FWCH_Plugin_Updater {

        private $plugin_file;
        private $github_username;
        private $github_repo;
        private $plugin_slug;
        private $version;
        private $github_response;

        public function __construct($plugin_file, $github_username, $github_repo) {
            $this->plugin_file = $plugin_file;
            $this->github_username = $github_username;
            $this->github_repo = $github_repo;
            $this->plugin_slug = plugin_basename($plugin_file);

            // Obtener versión local
            $plugin_data = get_plugin_data($plugin_file);
            $this->version = $plugin_data['Version'];

            add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
            add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
            add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);
        }

        /**
         * Verificar si hay una actualización disponible
         */
        public function check_for_update($transient) {
            if (empty($transient->checked)) {
                return $transient;
            }

            $this->get_github_info();

            if (!$this->github_response) {
                return $transient;
            }

            $remote_version = ltrim($this->github_response['tag_name'], 'v');

            if (version_compare($this->version, $remote_version, '<')) {
                $download_url = $this->get_download_url();

                $transient->response[$this->plugin_slug] = (object) [
                    'slug'        => dirname($this->plugin_slug),
                    'plugin'      => $this->plugin_slug,
                    'new_version' => $remote_version,
                    'url'         => $this->github_response['html_url'],
                    'package'     => $download_url,
                ];
            }

            return $transient;
        }

        /**
         * Info para el popup de detalles
         */
        public function plugin_info($false, $action, $response) {
            if (!isset($response->slug) || $response->slug != dirname($this->plugin_slug)) {
                return $false;
            }

            $this->get_github_info();

            if (!$this->github_response) {
                return $false;
            }

            $remote_version = ltrim($this->github_response['tag_name'], 'v');
            $download_url   = $this->get_download_url();

            return (object) [
                'slug'         => dirname($this->plugin_slug),
                'plugin_name'  => dirname($this->plugin_slug),
                'version'      => $remote_version,
                'author'       => $this->github_response['author']['login'],
                'homepage'     => $this->github_response['html_url'],
                'download_link'=> $download_url,
                'sections'     => [
                    'description' => $this->github_response['body'] ?: 'No description available.',
                    'changelog'   => 'See full changelog on GitHub',
                ],
            ];
        }

        /**
         * Después de la instalación, limpiar y renombrar la carpeta
         */
        public function after_install($true, $hook_extra, $result) {
            global $wp_filesystem;

            $plugin_folder = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname($this->plugin_slug);
            
            // GitHub creates folders with suffixes like "repo-name-main"
            // We need to find and rename them properly
            if (isset($result['destination'])) {
                // Check if destination folder exists and has the correct structure
                if ($wp_filesystem->exists($result['destination'])) {
                    // Move the contents to the correct plugin folder name
                    $wp_filesystem->move($result['destination'], $plugin_folder);
                    $result['destination'] = $plugin_folder;
                    
                    // Update destination name for WordPress
                    if (isset($result['destination_name'])) {
                        $result['destination_name'] = dirname($this->plugin_slug);
                    }
                }
            }

            return $result;
        }

        /**
         * Llamada a la API de GitHub
         */
        private function get_github_info() {
            if (!empty($this->github_response)) {
                return;
            }

            $request_uri = "https://api.github.com/repos/{$this->github_username}/{$this->github_repo}/releases/latest";

            $response = wp_remote_get($request_uri, [
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/vnd.github.v3+json',
                ]
            ]);

            if (is_wp_error($response)) {
                return;
            }

            if (wp_remote_retrieve_response_code($response) === 200) {
                $this->github_response = json_decode(wp_remote_retrieve_body($response), true);
            }
        }

        /**
         * URL de descarga: primero asset, luego fallback
         */
        private function get_download_url() {
            if (!empty($this->github_response['assets'][0]['browser_download_url'])) {
                return $this->github_response['assets'][0]['browser_download_url'];
            }

            return $this->github_response['zipball_url'];
        }
    }
}

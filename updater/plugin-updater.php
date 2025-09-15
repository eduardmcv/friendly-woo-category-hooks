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

            // Obtener versión del plugin
            $plugin_data = get_plugin_data($plugin_file);
            $this->version = $plugin_data['Version'];

            add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
            add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
            add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
        }

        /**
         * Verificar si hay actualizaciones disponibles
         */
        public function check_for_update($transient) {
            if (empty($transient->checked)) {
                return $transient;
            }

            // Obtener información del repositorio
            $this->get_github_info();

            if (version_compare($this->version, $this->github_response['tag_name'], '<')) {
                $transient->response[$this->plugin_slug] = (object) array(
                    'slug' => dirname($this->plugin_slug),
                    'plugin' => $this->plugin_slug,
                    'new_version' => $this->github_response['tag_name'],
                    'url' => $this->github_response['html_url'],
                    'package' => $this->github_response['zipball_url']
                );
            }

            return $transient;
        }

        /**
         * Obtener información del plugin para mostrar en el popup
         */
        public function plugin_info($false, $action, $response) {
            if (!isset($response->slug) || $response->slug != dirname($this->plugin_slug)) {
                return $false;
            }

            $this->get_github_info();

            return (object) array(
                'slug' => dirname($this->plugin_slug),
                'plugin_name' => dirname($this->plugin_slug),
                'version' => $this->github_response['tag_name'],
                'author' => $this->github_response['author']['login'],
                'homepage' => $this->github_response['html_url'],
                'download_link' => $this->github_response['zipball_url'],
                'sections' => array(
                    'description' => $this->github_response['body'],
                    'changelog' => 'Ver changelog completo en GitHub'
                )
            );
        }

        /**
         * Después de la instalación, limpiar y renombrar la carpeta
         */
        public function after_install($true, $hook_extra, $result) {
            global $wp_filesystem;

            $plugin_folder = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname($this->plugin_slug);
            $wp_filesystem->move($result['destination'], $plugin_folder);
            $result['destination'] = $plugin_folder;

            if (isset($result['destination_name'])) {
                $result['destination_name'] = dirname($this->plugin_slug);
            }

            return $result;
        }

        /**
         * Obtener información del repositorio de GitHub
         */
        private function get_github_info() {
            if (!empty($this->github_response)) {
                return;
            }

            $request_uri = "https://api.github.com/repos/{$this->github_username}/{$this->github_repo}/releases/latest";
            
            $response = wp_remote_get($request_uri, array(
                'timeout' => 10,
                'headers' => array(
                    'Accept' => 'application/vnd.github.v3+json',
                )
            ));

            if (is_wp_error($response)) {
                return;
            }

            $response_body = wp_remote_retrieve_body($response);
            $response_code = wp_remote_retrieve_response_code($response);

            if ($response_code === 200) {
                $this->github_response = json_decode($response_body, true);
            }
        }
    }
}
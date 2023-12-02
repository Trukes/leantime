<?php

namespace Leantime\Domain\Plugins\Services {

    use Exception;
    use Illuminate\Contracts\Container\BindingResolutionException;
    use Leantime\Core\Environment as EnvironmentCore;
    use Leantime\Core\Eventhelpers;
    use Leantime\Domain\Plugins\Models\MarketplacePlugin;
    use Leantime\Domain\Plugins\Repositories\Plugins as PluginRepository;
    use Leantime\Domain\Plugins\Models\InstalledPlugin;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Http\Client\RequestException;
    use Leantime\Domain\Setting\Services\Setting as SettingsService;
    use Illuminate\Support\Facades\File;
    use Illuminate\Support\Str;
    use Leantime\Domain\Users\Services\Users;

    /**
     *
     */
    class Plugins
    {

        use Eventhelpers;
        /**
         * @var PluginRepository
         */
        private PluginRepository $pluginRepository;

        /**
         * @var string
         */

        private string $pluginDirectory =  ROOT . "/../app/Plugins/";
        /**
         * @var EnvironmentCore
         */
        private EnvironmentCore $config;

        /**
         * Plugin types
         * custom: Plugin is loaded as a folder, available under discover plugins
         * system: Plugin is defined in config and loaded on start. Cannot delete, or disable plugin
         * marketplace: Plugin comes from maarketplace.
         *
         * @var array
         */
        private array $pluginTypes = [
            'custom' => "custom",
            'system' => "system",
            'marketplace' => 'marketplace',
        ];

        /**
         * Plugin formats
         * phar: Phar plugins (only from marketplace)
         * folder: Folder plugins
         *
         * @var array
         */
        private array $pluginFormat = [
            'phar' => 'phar',
            'folder' => 'phar',
        ];

        /**
         * Marketplace URL
         *
         * @var string
         */
        public string $marketplaceUrl = "https://marketplace.localhost";

        /**
         * @param PluginRepository $pluginRepository
         * @param EnvironmentCore  $config
         */
        public function __construct(PluginRepository $pluginRepository, EnvironmentCore $config)
        {
            $this->pluginRepository = $pluginRepository;
            $this->config = $config;
        }

        /**
         * @return array|false
         */
        public function getAllPlugins(bool $enabledOnly = false): false|array
        {
            $installedPluginsById = [];

            try {
                $installedPlugins = $this->pluginRepository->getAllPlugins($enabledOnly);
            } catch (\Exception $e) {
                $installedPlugins = [];
            }

            //Build array with pluginId as $key
            foreach ($installedPlugins as &$plugin) {
                $plugin->type = $plugin->format === $this->pluginFormat['phar']
                    ? $plugin->type = $this->pluginTypes['marketplace']
                    : $plugin->type = $this->pluginTypes['custom'];

                $installedPluginsById[$plugin->foldername] = $plugin;
            }

            // Gets plugins from the config, which are automatically enabled
            if (
                isset($this->config->plugins)
                && $configplugins = explode(',', $this->config->plugins)
            ) {
                collect($configplugins)
                ->filter(fn ($plugin) => ! empty($plugin))
                ->each(function ($plugin) use (&$installedPluginsById) {
                    $pluginModel = $this->createPluginFromComposer($plugin);

                    $installedPluginsById[$plugin] ??= $pluginModel;
                    $installedPluginsById[$plugin]->enabled = true;
                    $installedPluginsById[$plugin]->type = $this->pluginTypes['system'];
                });
            }

            /**
             * Filters array of plugins from database and config before returning
             * @var array $allPlugins
             */
            $allPlugins = static::dispatch_filter("beforeReturnAllPlugins", $installedPluginsById, array("enabledOnly" => $enabledOnly));

            return $allPlugins;
        }

        /**
         * @param $pluginFolder
         * @return bool
         * @throws BindingResolutionException
         */
        public function isPluginEnabled($pluginFolder): bool
        {

            $plugins = $this->getEnabledPlugins();

            foreach ($plugins as $plugin) {
                if (strtolower($plugin->foldername) == strtolower($pluginFolder)) {
                    return true;
                }
            }

            return false;
        }

        /**
         * @return array|false|mixed
         * @throws BindingResolutionException
         */
        public function getEnabledPlugins(): mixed
        {

            if (isset($_SESSION['enabledPlugins'])) {

                $enabledPlugins = static::dispatch_filter("beforeReturnCachedPlugins", $_SESSION['enabledPlugins'], array("enabledOnly" => true));
                return $enabledPlugins;
            }

            $_SESSION['enabledPlugins'] = $this->getAllPlugins(enabledOnly: true);

            /**
             * Filters session array of enabled plugins before returning
             * @var array $enabledPlugins
             */
            $enabledPlugins = static::dispatch_filter("beforeReturnCachedPlugins", $_SESSION['enabledPlugins'], array("enabledOnly" => true));
            return $enabledPlugins;
        }

        /**
         * @return InstalledPlugin[]
         * @throws BindingResolutionException
         */
        public function discoverNewPlugins(): array
        {
            $installedPluginNames = array_map(fn ($plugin) => $plugin->foldername, $this->getAllPlugins());
            $scanned_directory = array_diff(scandir($this->pluginDirectory), ['..', '.']);

            $newPlugins = collect($scanned_directory)
                ->filter(fn ($directory) => is_dir("{$this->pluginDirectory}/{$directory}") && ! array_search($directory, $installedPluginNames))
                ->map(function ($directory) {
                    try {
                        return $this->createPluginFromComposer($directory);
                    } catch (\Exception $e) {
                        return null;
                    }
                })
                ->filter()->all();

            return $newPlugins;
        }

        public function createPluginFromComposer(string $pluginFolder, string $license_key = ''): InstalledPlugin
        {
            $pluginPath = Str::finish($this->pluginDirectory, DIRECTORY_SEPARATOR) . Str::finish($pluginFolder, DIRECTORY_SEPARATOR);

            if (file_exists($composerPath = $pluginPath . 'composer.json')) {
                $format = 'folder';
            } elseif (file_exists($composerPath = "phar://{$pluginPath}{$pluginFolder}.phar" . DIRECTORY_SEPARATOR . 'composer.json')) {
                $format = 'phar';
            } else {
                throw new \Exception('Could not find composer.json');
            }

            $json = file_get_contents($composerPath);
            $pluginFile = json_decode($json, true);

            $plugin = app()->make(InstalledPlugin::class);
            $plugin->name = $pluginFile['name'];
            $plugin->enabled = 0;
            $plugin->description = $pluginFile['description'];
            $plugin->version = $pluginFile['version'];
            $plugin->installdate = date("y-m-d");
            $plugin->foldername = $pluginFolder;
            $plugin->license = $license_key;
            $plugin->format = $format;
            $plugin->homepage = $pluginFile['homepage'];
            $plugin->authors = json_encode($pluginFile['authors']);

            return $plugin;
        }

        /**
         * @param $pluginFolder
         * @return false|string
         * @throws BindingResolutionException
         */
        public function installPlugin($pluginFolder): false|string
        {
            $pluginFolder = Str::studly($pluginFolder);

            try {
                $plugin = $this->createPluginFromComposer($pluginFolder);
            } catch (\Exception $e) {
                error_log($e);
                return false;
            }

            $pluginClassName = $this->getPluginClassName($plugin);
            $newPluginSvc = app()->make($pluginClassName);

            if (method_exists($newPluginSvc, "install")) {
                try {
                    $newPluginSvc->install();
                } catch (Exception $e) {
                    error_log($e);
                    return false;
                }
            }

            return $this->pluginRepository->addPlugin($plugin);
        }

        /**
         * @param int $id
         * @return bool
         */
        public function enablePlugin(int $id): bool
        {
            unset($_SESSION['enabledPlugins']);

            $pluginModel = $this->pluginRepository->getPlugin($id);

            if ($pluginModel->format == 'phar') {
                $phar = new \Phar(
                    Str::finish($this->pluginDirectory, DIRECTORY_SEPARATOR)
                    . Str::finish($pluginModel->foldername, DIRECTORY_SEPARATOR)
                    . Str::finish($pluginModel->foldername, '.phar')
                );

                $signature = $phar->getSignature();

                $response = Http::withoutVerifying()->get("$this->marketplaceUrl", [
                    'request' => 'activation',
                    'license_key' => $pluginModel->license,
                    'product_id' => $pluginModel->id,
                    'instance' => app()
                        ->make(SettingsService::class)
                        ->getCompanyId(),
                    'phar_hash' => $signature,
                ]);

                if (! $response->ok()) {
                    return false;
                }
            }

            return $this->pluginRepository->enablePlugin($id);
        }

        /**
         * @param int $id
         * @return bool
         */
        public function disablePlugin(int $id): bool
        {
            unset($_SESSION['enabledPlugins']);

            $pluginModel = $this->pluginRepository->getPlugin($id);

            if ($pluginModel->format == 'phar') {
                $phar = new \Phar(
                    Str::finish($this->pluginDirectory, DIRECTORY_SEPARATOR)
                    . Str::finish($pluginModel->foldername, DIRECTORY_SEPARATOR)
                    . Str::finish($pluginModel->foldername, '.phar')
                );

                $signature = $phar->getSignature();

                $response = Http::get("$this->marketplaceUrl", [
                    'request' => 'deactivation',
                    'license_key' => $pluginModel->license,
                    'product_id' => $pluginModel->id,
                    'instance' => app()
                        ->make(SettingsService::class)
                        ->getCompanyId(),
                    'phar_hash' => $signature,
                ]);

                if (! $response->ok()) {
                    return false;
                }
            }

            return $this->pluginRepository->disablePlugin($id);
        }

        /**
         * @param int $id
         * @return bool
         * @throws BindingResolutionException
         */
        public function removePlugin(int $id): bool
        {
            unset($_SESSION['enabledPlugins']);
            /** @var PluginModel|false $plugin */
            $plugin = $this->pluginRepository->getPlugin($id);

            if (! $plugin) {
                return false;
            }

            //Any installation calls should happen right here.
            $pluginClassName = $this->getPluginClassName($plugin);
            $newPluginSvc = app()->make($pluginClassName);

            if (method_exists($newPluginSvc, "uninstall")) {
                try {
                    $newPluginSvc->uninstall();
                } catch (\Exception $e) {
                    error_log($e);
                    return false;
                }
            }

            return $this->pluginRepository->removePlugin($id);

            //TODO remove files savely
        }

        /**
         * @param InstalledPlugin $plugin
         * @return string
         * @throws BindingResolutionException
         */
        public function getPluginClassName(InstalledPlugin $plugin): string
        {
            return app()->getNamespace()
                . 'Plugins\\'
                . Str::studly($plugin->foldername)
                . '\\Services\\'
                . Str::studly($plugin->fodlername);
        }

        /**
         * @param int    $page
         * @param string $query
         * @return MarketplacePlugin[]
         */
        public function getMarketplacePlugins(int $page, string $query = ''): array
        {
            $plugins = Http::withoutVerifying()->get(
                "{$this->marketplaceUrl}/ltmp-api"
                . (! empty($query) ? "/search/$query" : '/index')
                . "/$page"
            );

            $pluginArray = $plugins->collect()->toArray();

            $plugins = [];

            if(isset($pluginArray["data"] )) {

                foreach ($pluginArray["data"] as $plugin) {
                    $pluginModel = app()->make(MarketplacePlugin::class);
                    $pluginModel->identifier = $plugin['identifier'];
                    $pluginModel->name = $plugin['post_title'];
                    $pluginModel->excerpt = $plugin['excerpt'];
                    $pluginModel->imageUrl = $plugin['featured_image'];
                    $pluginModel->authors = ''; //TODO Send from marketplace
                    $plugins[] = $pluginModel;
                }
            }

            return $plugins;
        }

        /**
         * @param string $identifier
         * @return MarketplacePlugin[]
         */
        public function getMarketplacePlugin(string $identifier): array
        {
            return Http::withoutVerifying()->get("$this->marketplaceUrl/ltmp-api/versions/$identifier")
                ->collect()
                ->mapWithKeys(function ($data, $version) use ($identifier) {
                    static $count;
                    $count ??= 0;

                    $pluginModel = app()->make(MarketplacePlugin::class);
                    $pluginModel->identifier = $identifier;
                    $pluginModel->name = $data['name'];
                    $pluginModel->excerpt = '';
                    $pluginModel->description = $data['description'];
                    $pluginModel->marketplaceUrl = $data['marketplace_url'];
                    $pluginModel->thumbnailUrl = $data['thumbnail_url'] ?: '';
                    $pluginModel->authors = $data['author'];
                    $pluginModel->version = $version;
                    $pluginModel->price = $data['price'];
                    $pluginModel->license = $data['license'];
                    $pluginModel->rating = $data['rating'];
                    $pluginModel->marketplaceId = $data['product_id'];

                    return [$count++ => $pluginModel];
                })
                ->all();
        }

        /**
         * @param MarketplacePlugin $plugin
         * @return void
         * @throws Illuminate\Http\Client\RequestException|Exception
         */
        public function installMarketplacePlugin(MarketplacePlugin $plugin): void
        {
            $response = Http::withoutVerifying()->withHeaders([
                    'X-License-Key' => $plugin->license,
                    'X-Instance-Id' => app()
                        ->make(SettingsService::class)
                        ->getCompanyId(),
                    'X-User-Count' => count(app()
                        ->make(Users::class)->getAll(true))
                ])
                ->get("{$this->marketplaceUrl}/ltmp-api/download/{$plugin->marketplaceId}");

            $response->throwIf(in_array(true, [
                ! $response->ok(),
                $response->header('Content-Type') !== 'application/zip'
            ]), fn () => new RequestException($response));

            $filename = $response->header('Content-Disposition');
            $filename = substr($filename, strpos($filename, 'filename=') + 9);

            if (! str_starts_with($filename, $plugin->identifier)) {
                throw new \Exception('Wrong file downloaded');
            }

            if (! file_put_contents(
                $temporaryFile = Str::finish(sys_get_temp_dir(), '/') . $filename,
                $response->body()
            )) {
                throw new \Exception('Could not download plugin');
            }

            if (
                is_dir($pluginDir = "{$this->pluginDirectory}{$plugin->identifier}")
                && ! File::deleteDirectory($pluginDir)
            ) {
                throw new \Exception('Could not remove existing plugin');
            }

            mkdir($pluginDir);

            $zip = new \ZipArchive();

            match ($zip->open($temporaryFile)) {
                \ZipArchive::ER_EXISTS => throw new \Exception('Zip: File already exists'),
                \ZipArchive::ER_INCONS => throw new \Exception('Zip: Archive inconsistent'),
                \ZipArchive::ER_INVAL => throw new \Exception('Zip: Invalid argument'),
                \ZipArchive::ER_MEMORY => throw new \Exception('Zip: Malloc failure'),
                \ZipArchive::ER_NOENT => throw new \Exception('Zip: No such file'),
                \ZipArchive::ER_NOZIP => throw new \Exception('Zip: Not a zip archive'),
                \ZipArchive::ER_OPEN => throw new \Exception('Zip: Can\'t open file'),
                \ZipArchive::ER_READ => throw new \Exception('Zip: Read error'),
                \ZipArchive::ER_SEEK => throw new \Exception('Zip: Seek error'),
                default => throw new \Exception('Zip: Unknown error'),
                true => null,
            };

            if (! $zip->extractTo($pluginDir)) {
                throw new \Exception('Could not extract plugin');
            }

            $zip->close();

            unlink($temporaryFile);

            # read the composer.json content from the plugin phar file
            $pluginModel = $this->createPluginFromComposer($plugin->identifier, $plugin->license);

            if (! $this->pluginRepository->addPlugin($pluginModel)) {
                throw new \Exception('Could not add plugin to database');
            }

            unset($_SESSION['enabledPlugins']);
        }
    }
}

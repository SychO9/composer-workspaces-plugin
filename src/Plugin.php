<?php


namespace ComposerWorkspacesPlugin;


use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackage;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Repository\RepositoryFactory;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use ComposerWorkspacesPlugin\Commands\CommandProvider;
use RuntimeException;

class Plugin implements PluginInterface, Capable, EventSubscriberInterface
{
    const VERSION = '2.0.0';
    const POST_CALLBACK_PRIORITY = 50001;

    /** @var Composer */
    protected $composer;
    /** @var IOInterface */
    protected $io;

    /**
     * Apply plugin modifications to Composer
     *
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;

        if ($this->isWorkspace()) {
            $workspaceRoot = $this->getWorkspaceRoot();

            $workspacePath = getcwd();

            $workspace = $workspaceRoot->resolveWorkspace($workspacePath);

            if ($workspace === null) {
                throw new RuntimeException('Could not resolve workspace for path "' . $workspacePath . '"');
            }

            $this->configureWorkspace($workspaceRoot, $workspace, $composer);
        }
    }

    /**
     * @return bool
     */
    public function isWorkspace()
    {
        return isset($this->composer->getPackage()->getExtra()['workspace-root']);
    }

    /**
     * @return WorkspaceRoot|null
     */
    public function getWorkspaceRoot()
    {
        $registry = new WorkspaceRootRegistry($this->io);

        if ($this->isWorkspaceRoot()) {
            return $registry->createWorkspaceRoot(getcwd(), $this->composer->getPackage());
        }

        if ($this->isWorkspace()) {
            $workspaceConfig = WorkspaceConfig::fromPackage($this->composer->getPackage());
            return $registry->createWorkspaceRoot($workspaceConfig->getWorkspaceRootDirectory());
        }

        return null;
    }

    /**
     * @return bool
     */
    public function isWorkspaceRoot()
    {
        return isset($this->composer->getPackage()->getExtra()['workspaces']);
    }

    protected function configureWorkspace(WorkspaceRoot $workspaceRoot, Workspace $workspace, Composer $composer)
    {
        $repositoryManager = $composer->getRepositoryManager();

        foreach ($workspaceRoot->getWorkspaces() as $otherWorkspace) {
            if ($workspace->getRelativePath() === $otherWorkspace->getRelativePath()) {
                continue;
            }

            $repositoryConfig = [
                'type' => 'path',
                'url' => $otherWorkspace->getPathRelativeTo($workspace->getAbsolutePath())
            ];

            $repository = $repositoryManager->createRepository('path', $repositoryConfig);
            $repositoryManager->prependRepository($repository);
        }
    }

    /**
     * Method by which a Plugin announces its API implementations, through an array
     * with a special structure.
     *
     * The key must be a string, representing a fully qualified class/interface name
     * which Composer Plugin API exposes.
     * The value must be a string as well, representing the fully qualified class name
     * of the implementing class.
     *
     * @tutorial
     *
     * return array(
     *     'Composer\Plugin\Capability\CommandProvider' => 'My\CommandProvider',
     *     'Composer\Plugin\Capability\Validator'       => 'My\Validator',
     * );
     *
     * @return string[]
     */
    public function getCapabilities()
    {
        return [
            \Composer\Plugin\Capability\CommandProvider::class => CommandProvider::class,
        ];
    }

    /**
     * @return string
     */
    public function getVersion() {
        return self::VERSION;
    }

     /**
     * @return null
     */
    public function deactivate(Composer $composer, IOInterface $io) {}

     /**
     * @return null
     */
    public function uninstall(Composer $composer, IOInterface $io) {}

    public static function getSubscribedEvents()
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL =>
                ['onPostInstallOrUpdate', self::POST_CALLBACK_PRIORITY],
            ScriptEvents::POST_INSTALL_CMD =>
                ['onPostInstallOrUpdate', self::POST_CALLBACK_PRIORITY],
            ScriptEvents::POST_UPDATE_CMD =>
                ['onPostInstallOrUpdate', self::POST_CALLBACK_PRIORITY]
        ];
    }

    public function onPostInstallOrUpdate(Event $event): void
    {
        $this->cleanUpAfterMergePlugin($event);
        $this->symlinkVendor($event);
    }

    /**
     * Cleans up after the merge plugin's pre listeners but before its post listeners.
     */
    protected function cleanUpAfterMergePlugin(Event $event): void
    {
        $event->getIO()->write("Cleaning up after merge plugin..");

        $root = $this->getWorkspaceRoot();
        $rootPackage = $this->composer->getPackage();
        $composerJson = (new ArrayDumper())->dump($rootPackage);

        $initialComposerJsonFile = $root->getComposerJson();
        $initialComposerJson = $initialComposerJsonFile->read();

        $workspaceNames = array_map(function (Workspace $workspace) {
            return $workspace->getName();
        }, $root->getWorkspaces());

        $initialComposerJson['require'] = array_diff_key($composerJson['require'], $workspaceNames);
        $initialComposerJson['require-dev'] = array_diff_key($composerJson['require-dev'], $workspaceNames);

        foreach (['autoload', 'autoload-dev'] as $copyKey) {
            $initialComposerJson[$copyKey] = $composerJson[$copyKey];
        }

        $this->fixRepositories($initialComposerJson, $event);

        file_put_contents($root->getComposerFilePath(), json_encode($initialComposerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function fixRepositories(array $initialComposerJson, Event $event)
    {
        $rootPackage = $this->loadPackage($initialComposerJson);
        // $this->composer->setPackage($rootPackage);
        $rm = RepositoryFactory::manager(
            $event->getIO(),
            $this->composer->getConfig(),
            Factory::createHttpDownloader($event->getIO(), $this->composer->getConfig())
        );

        foreach ($rootPackage->getRepositories() as $repository) {
            $rm->addRepository($repository);
        }

        $this->composer->setRepositoryManager($rm);
    }

    /**
     * Symlinks key vendor files from the workspace to the vendor directory.
     */
    protected function symlinkVendor(Event $event)
    {
        if (! $this->isWorkspaceRoot()) {
            return;
        }

        $this->io->write('Symlinking vendor for workspaces..');

        $root = $this->getWorkspaceRoot();
        $rootVendor = $root->getVendorDirectory();

        foreach ($root->getWorkspaces() as $workspace) {
            $vendor = $workspace->getVendorDirectory();

            $workspace->deleteVendorDirectory();

            symlink("$rootVendor", "$vendor");
        }
    }

    /**
     * @param array $json
     * @return BasePackage|\Composer\Package\CompleteAliasPackage|CompletePackage|\Composer\Package\RootAliasPackage|RootPackage
     */
    private function loadPackage(array $json)
    {
        // Dummy version required.
        if (! isset($json['version'])) {
            $json['version'] = '1.0.0';
        }

        return (new ArrayLoader())->load($json, RootPackage::class);
    }
}

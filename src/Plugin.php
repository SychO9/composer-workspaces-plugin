<?php


namespace ComposerWorkspacesPlugin;


use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use ComposerWorkspacesPlugin\Commands\CommandProvider;
use RuntimeException;

class Plugin implements PluginInterface, Capable, EventSubscriberInterface
{
    const VERSION = '2.0.0';

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
            'post-install-cmd' => 'symlinkWorkspaceVendors',
            'post-update-cmd' => 'symlinkWorkspaceVendors'
        ];
    }

    /**
     * Symlinks key vendor files from the workspace to the vendor directory.
     */
    public function symlinkWorkspaceVendors(Event $event)
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
}

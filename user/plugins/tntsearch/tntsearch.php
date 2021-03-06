<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Grav;
use Grav\Common\Page\Page;
use Grav\Common\Plugin;
use Grav\Common\Scheduler\Scheduler;
use Grav\Plugin\TNTSearch\GravTNTSearch;
use RocketTheme\Toolbox\Event\Event;
use TeamTNT\TNTSearch\Exceptions\IndexNotFoundException;

/**
 * Class TNTSearchPlugin
 * @package Grav\Plugin
 */
class TNTSearchPlugin extends Plugin
{
    protected $results = [];
    protected $query;

    protected $built_in_search_page;
    protected $query_route;
    protected $search_route;
    protected $current_route;
    protected $admin_route;

    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized'      => [
                ['autoload', 100000],
                ['onPluginsInitialized', 0]
            ],
            'onSchedulerInitialized'    => ['onSchedulerInitialized', 0],
            'onTwigLoader'              => ['onTwigLoader', 0],
            'onTNTSearchReIndex'        => ['onTNTSearchReIndex', 0],
            'onTNTSearchIndex'          => ['onTNTSearchIndex', 0],
            'onTNTSearchQuery'          => ['onTNTSearchQuery', 0],
            'onFlexObjectSave'          => ['onObjectSave', 0],
            'onFlexObjectDelete'        => ['onObjectDelete', 0],
        ];
    }

    /**
     * [onPluginsInitialized:100000] Composer autoload.
     *is
     * @return ClassLoader
     */
    public function autoload()
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized()
    {
        if ($this->isAdmin()) {

            $this->GravTNTSearch();
            $route = $this->config->get('plugins.admin.route');
            $base = '/' . trim($route, '/');
            $this->admin_route = $this->grav['base_url'] . $base;

            $this->enable([
                'onAdminMenu' => ['onAdminMenu', 0],
                'onAdminTaskExecute' => ['onAdminTaskExecute', 0],
                'onTwigSiteVariables' => ['onTwigAdminVariables', 0],
                'onTwigLoader' => ['addAdminTwigTemplates', 0],
            ]);

            if ($this->config->get('plugins.tntsearch.enable_admin_page_events', true)) {
                $this->enable([
                    'onAdminAfterSave' => ['onObjectSave', 0],
                    'onAdminAfterDelete' => ['onObjectDelete', 0],
                ]);
            }

            return;
        }

        $this->enable([
            'onPagesInitialized' => ['onPagesInitialized', 1000],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
        ]);
    }

    /**
     * Add index job to Grav Scheduler
     * Requires Grav 1.6.0 - Scheduler
     */
    public function onSchedulerInitialized(Event $e)
    {
        if ($this->config->get('plugins.tntsearch.scheduled_index.enabled')) {
            /** @var Scheduler $scheduler */
            $scheduler = $e['scheduler'];
            $at = $this->config->get('plugins.tntsearch.scheduled_index.at');
            $logs = $this->config->get('plugins.tntsearch.scheduled_index.logs');
            $job = $scheduler->addFunction('Grav\Plugin\TNTSearchPlugin::indexJob', [], 'tntsearch-index');
            $job->at($at);
            $job->output($logs);
            $job->backlink('/plugins/tntsearch');
        }
    }

    /**
     * Function to force a reindex from your own plugins
     */
    public function onTNTSearchReIndex()
    {
        $this->GravTNTSearch()->createIndex();
    }

    /**
     * A sample event to show how easy it is to extend the indexing fields
     *
     * @param Event $e
     */
    public function onTNTSearchIndex(Event $e)
    {
        $page = $e['page'];
        $fields = $e['fields'];

        if ($page && $page instanceof Page && isset($page->header()->author)) {
            $author = $page->header()->author;
            if (is_string($author)) {
                $fields->author = $author;
            }
        }
    }

    public function onTNTSearchQuery(Event $e)
    {
        $page = $e['page'];
        $query = $e['query'];
        $options = $e['options'];
        $fields = $e['fields'];
        $gtnt = $e['gtnt'];

        $content = $gtnt->getCleanContent($page);
        $title = $page->title();

        $relevant = $gtnt->tnt->snippet($query, $content, $options['snippet']);

        if (strlen($relevant) <= 6) {
            $relevant = substr($content, 0, $options['snippet']);
        }

        $fields->hits[] = [
            'link' => $page->route(),
            'title' =>  $gtnt->tnt->highlight($title, $query, 'em', ['wholeWord' => false]),
            'content' =>  $gtnt->tnt->highlight($relevant, $query, 'em', ['wholeWord' => false]),
        ];
    }

    /**
     * Create pages and perform the search actions
     */
    public function onPagesInitialized()
    {
        /** @var Uri $uri */
        $uri = $this->grav['uri'];

        $options = [];

        $this->current_route = $uri->path();

        $this->built_in_search_page = $this->config->get('plugins.tntsearch.built_in_search_page');
        $this->search_route = $this->config->get('plugins.tntsearch.search_route');
        $this->query_route = $this->config->get('plugins.tntsearch.query_route');

        $pages = $this->grav['pages'];
        $page = $pages->dispatch($this->current_route);

        if (!$page) {
            if ($this->query_route && $this->query_route == $this->current_route) {
                $page = new Page;
                $page->init(new \SplFileInfo(__DIR__ . "/pages/tntquery.md"));
                $page->slug(basename($this->current_route));
                if ($uri->param('ajax') || $uri->query('ajax')) {
                    $page->template('tntquery-ajax');
                }
                $pages->addPage($page, $this->current_route);
            } elseif ($this->built_in_search_page && $this->search_route == $this->current_route) {
                $page = new Page;
                $page->init(new \SplFileInfo(__DIR__ . "/pages/search.md"));
                $page->slug(basename($this->current_route));
                $pages->addPage($page, $this->current_route);
            }
        }

        $this->query = $uri->param('q') ?: $uri->query('q');

        if ($this->query) {

            $snippet = $this->getFormValue('sl');
            $limit = $this->getFormValue('l');

            if ($snippet) {
                $options['snippet'] = $snippet;
            }
            if ($limit) {
                $options['limit'] = $limit;
            }

            $this->grav['tntsearch'] = $this->getSearchObjectType($options);

            if ($page) {
                $this->config->set('plugins.tntsearch', $this->mergeConfig($page));
            }

            try {
                $this->results = $this->GravTNTSearch()->search($this->query);
            } catch (IndexNotFoundException $e) {
                $this->results = ['number_of_hits' => 0, 'hits' => [], 'execution_time' => 'missing index'];
            }
        }
    }

    /**
     * Add the Twig template paths to the Twig laoder
     */
    public function onTwigLoader()
    {
        $this->grav['twig']->addPath(__DIR__ . '/templates');
    }

    /**
     * Add the current template paths to the admin Twig loader
     */
    public function addAdminTwigTemplates()
    {
        $this->grav['twig']->addPath($this->grav['locator']->findResource('theme://templates'));
    }

    /**
     * Add results and query to Twig as well as CSS/JS assets
     */
    public function onTwigSiteVariables()
    {
        $twig = $this->grav['twig'];

        if ($this->query) {
            $twig->twig_vars['query'] = $this->query;
            $twig->twig_vars['tntsearch_results'] = $this->results;
        }

        if ($this->config->get('plugins.tntsearch.built_in_css')) {
            $this->grav['assets']->addCss('plugin://tntsearch/assets/tntsearch.css');
        }
        if ($this->config->get('plugins.tntsearch.built_in_js')) {
            // $this->grav['assets']->addJs('plugin://tntsearch/assets/tntsearch.js');
            $this->grav['assets']->addJs('plugin://tntsearch/assets/tntsearch.js');
        }
    }

    /**
     * Handle the Reindex task from the admin
     *
     * @param Event $e
     */
    public function onAdminTaskExecute(Event $e)
    {
        if ($e['method'] == 'taskReindexTNTSearch') {

            $controller = $e['controller'];

            header('Content-type: application/json');

            if (!$controller->authorizeTask('reindexTNTSearch', ['admin.configuration', 'admin.super'])) {
                $json_response = [
                    'status'  => 'error',
                    'message' => '<i class="fa fa-warning"></i> Index not created',
                    'details' => 'Insufficient permissions to reindex the search engine database.'
                ];
                echo json_encode($json_response);
                exit;
            }

            // disable warnings
            error_reporting(1);
            // disable execution time
            set_time_limit(0);

            list($status, $msg, $output) = $this->indexJob();

            $json_response = [
                'status'  => $status ? 'success' : 'error',
                'message' => $msg
            ];

            echo json_encode($json_response);
            exit;
        }

    }

    /**
     * Perform an 'add' or 'update' for index data as needed
     *
     * @param $event
     * @return bool
     */
    public function onObjectSave($event)
    {
        $obj = $event['object'] ?: $event['page'];

        if ($obj) {
            $this->GravTNTSearch()->updateIndex($obj);
        }

        return true;
    }

    /**
     * Perform a 'delete' for index data as needed
     *
     * @param $event
     * @return bool
     */
    public function onObjectDelete($event)
    {
        $obj = $event['object'] ?: $event['page'];

        if ($obj) {
            $this->GravTNTSearch()->deleteIndex($obj);
        }

        return true;
    }

    /**
     * Set some twig vars and load CSS/JS assets for admin
     */
    public function onTwigAdminVariables()
    {
        $twig = $this->grav['twig'];
        $gtnt = $this->GravTNTSearch();

        list($status, $msg) = $this->getIndexCount($gtnt);

        if ($status === false) {
            $message = '<i class="fa fa-binoculars"></i> <a href="/'. trim($this->admin_route, '/') . '/plugins/tntsearch">TNTSearch must be indexed before it will function properly.</a>';
            $this->grav['admin']->addTempMessage($message, 'error');
        }

        $twig->twig_vars['tntsearch_index_status'] = ['status' => $status, 'msg' => $msg];
        $this->grav['assets']->addCss('plugin://tntsearch/assets/admin/tntsearch.css');
        $this->grav['assets']->addJs('plugin://tntsearch/assets/admin/tntsearch.js');
    }

    /**
     * Add reindex button to the admin QuickTray
     */
    public function onAdminMenu()
    {
        $options = [
            'authorize' => 'taskReindexTNTSearch',
            'hint' => 'reindexes the TNT Search index',
            'class' => 'tntsearch-reindex',
            'icon' => 'fa-binoculars'
        ];
        $this->grav['twig']->plugins_quick_tray['TNT Search'] = $options;
    }

    /**
     * Wrapper to get the number of documents currently indexed
     *
     * @param $gtnt GravTNTSearch
     * @return array
     */
    protected static function getIndexCount($gtnt)
    {
        $status = true;
        try {
            $msg = '';
            $gtnt->selectIndex();
            $doc_count = $gtnt->tnt->totalDocumentsInCollection();

            $language = Grav::instance()['language'];
            if ($language->enabled()) {
                $msg .= 'Processed ' . count($language->getLanguages()) . ' languages, each with ';
            }
            $msg .=  $doc_count . ' documents reindexed';
        } catch (IndexNotFoundException $e) {
            $status = false;
            $msg = "Index not created";
        }

        return [$status, $msg];
    }

    /**
     * Helper function to read form/url values
     *
     * @param $val
     * @return mixed
     */
    protected function getFormValue($val)
    {
        $uri = $this->grav['uri'];
        return $uri->param($val) ?: $uri->query($val) ?: filter_input(INPUT_POST, $val, FILTER_SANITIZE_ENCODED);;
    }

    public static function getSearchObjectType($options = [])
    {
        $type = 'Grav\\Plugin\\TNTSearch\\' . Grav::instance()['config']->get('plugins.tntsearch.search_object_type', 'Grav') . 'TNTSearch';
        if (class_exists($type)) {
            return new $type($options);
        } else {
            throw new \RuntimeException('Search class: ' . $type . ' does not exist');
        }
    }

    public static function indexJob()
    {
        $grav = Grav::instance();

        $grav['debugger']->enabled(false);
        $grav['twig']->init();

        $language = $grav['language'];

        ob_start();

        if ($language->enabled()) {
            foreach ($language->getLanguages() as $lang) {
                $language->init();
                $language->setActive($lang);

                echo("\nLanguage: $lang\n");
                $grav['pages']->init();
                $gtnt = static::getSearchObjectType();
                $gtnt->createIndex();
            }
        } else {
            $grav['pages']->init();
            $gtnt = static::getSearchObjectType();
            $gtnt->createIndex();
        }
        
        $output = ob_get_clean();

        // Reset and get index count and status
        $gtnt = static::getSearchObjectType();
        list($status, $msg) = static::getIndexCount($gtnt);

        return [$status, $msg, $output];
    }

    /**
     * Helper to initialize TNTSearch if required
     *
     * @return TNTSearch\GravTNTSearch
     */
    protected function GravTNTSearch()
    {
        if (!isset($this->grav['tntsearch'])) {
            $this->grav['tntsearch'] = static::getSearchObjectType();
        }

        return $this->grav['tntsearch'];
    }

}

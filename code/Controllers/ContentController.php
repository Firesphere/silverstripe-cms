<?php

namespace SilverStripe\CMS\Controllers;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\Session;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Convert;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataModel;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\FieldType\DBVarchar;
use SilverStripe\ORM\SS_List;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\ArrayData;
use SilverStripe\View\Requirements;
use SilverStripe\View\SSViewer;
use Translatable;

/**
 * The most common kind of controller; effectively a controller linked to a {@link DataObject}.
 *
 * ContentControllers are most useful in the content-focused areas of a site.  This is generally
 * the bulk of a site; however, they may be less appropriate in, for example, the user management
 * section of an application.
 *
 * On its own, content controller does very little.  Its constructor is passed a {@link DataObject}
 * which is stored in $this->dataRecord.  Any unrecognised method calls, for example, Title()
 * and Content(), will be passed along to the data record,
 *
 * Subclasses of ContentController are generally instantiated by ModelAsController; this will create
 * a controller based on the URLSegment action variable, by looking in the SiteTree table.
 *
 * @todo Can this be used for anything other than SiteTree controllers?
 */
class ContentController extends Controller
{

    protected $dataRecord;

    private static $extensions = array('SilverStripe\\CMS\\Controllers\\OldPageRedirector');

    private static $allowed_actions = array(
        'successfullyinstalled',
        'deleteinstallfiles', // secured through custom code
        'LoginForm'
    );

    /**
     * The ContentController will take the URLSegment parameter from the URL and use that to look
     * up a SiteTree record.
     *
     * @param SiteTree $dataRecord
     */
    public function __construct($dataRecord = null)
    {
        if (!$dataRecord) {
            $dataRecord = new SiteTree();
            if ($this->hasMethod("Title")) {
                $dataRecord->Title = $this->Title();
            }
            $dataRecord->URLSegment = static::class;
            $dataRecord->ID = -1;
        }

        $this->dataRecord = $dataRecord;

        parent::__construct();

        $this->setFailover($this->dataRecord);
    }

    /**
     * Return the link to this controller, but force the expanded link to be returned so that form methods and
     * similar will function properly.
     *
     * @param string|null $action Action to link to.
     * @return string
     */
    public function Link($action = null)
    {
        return $this->data()->Link(($action ? $action : true));
    }

    //----------------------------------------------------------------------------------//
    // These flexible data methods remove the need for custom code to do simple stuff

    /**
     * Return the children of a given page. The parent reference can either be a page link or an ID.
     *
     * @param string|int $parentRef
     * @return SS_List
     */
    public function ChildrenOf($parentRef)
    {
        $parent = SiteTree::get_by_link($parentRef);

        if (!$parent && is_numeric($parentRef)) {
            $parent = DataObject::get_by_id('SilverStripe\\CMS\\Model\\SiteTree', $parentRef);
        }

        if ($parent) {
            return $parent->Children();
        }
        return null;
    }

    /**
     * @param string $link
     * @return SiteTree
     */
    public function Page($link)
    {
        return SiteTree::get_by_link($link);
    }

    protected function init()
    {
        parent::init();

        // If we've accessed the homepage as /home/, then we should redirect to /.
        if ($this->dataRecord instanceof SiteTree
            && RootURLController::should_be_on_root($this->dataRecord)
            && (!isset($this->urlParams['Action']) || !$this->urlParams['Action'] )
            && !$_POST && !$_FILES && !$this->redirectedTo()
        ) {
            $getVars = $_GET;
            unset($getVars['url']);
            if ($getVars) {
                $url = "?" . http_build_query($getVars);
            } else {
                $url = "";
            }
            $this->redirect($url, 301);
            return;
        }

        if ($this->dataRecord) {
            $this->dataRecord->extend('contentcontrollerInit', $this);
        } else {
            SiteTree::singleton()->extend('contentcontrollerInit', $this);
        }

        if ($this->redirectedTo()) {
            return;
        }

        // Check page permissions
        /** @skipUpgrade */
        if ($this->dataRecord && $this->URLSegment != 'Security' && !$this->dataRecord->canView()) {
            Security::permissionFailure($this);
            return;
        }
    }

    /**
     * This acts the same as {@link Controller::handleRequest()}, but if an action cannot be found this will attempt to
     * fall over to a child controller in order to provide functionality for nested URLs.
     *
     * @param HTTPRequest $request
     * @param DataModel $model
     * @return HTTPResponse
     * @throws HTTPResponse_Exception
     */
    public function handleRequest(HTTPRequest $request, DataModel $model)
    {
        /** @var SiteTree $child */
        $child  = null;
        $action = $request->param('Action');
        $this->setDataModel($model);

        // If nested URLs are enabled, and there is no action handler for the current request then attempt to pass
        // control to a child controller. This allows for the creation of chains of controllers which correspond to a
        // nested URL.
        if ($action && SiteTree::config()->nested_urls && !$this->hasAction($action)) {
            // See ModelAdController->getNestedController() for similar logic
            if (class_exists('Translatable')) {
                Translatable::disable_locale_filter();
            }
            // look for a page with this URLSegment
            $child = SiteTree::get()->filter(array(
                'ParentID' => $this->ID,
                'URLSegment' => rawurlencode($action)
            ))->first();
            if (class_exists('Translatable')) {
                Translatable::enable_locale_filter();
            }
        }

        // we found a page with this URLSegment.
        if ($child) {
            $request->shiftAllParams();
            $request->shift();

            $response = ModelAsController::controller_for($child)->handleRequest($request, $model);
        } else {
            // If a specific locale is requested, and it doesn't match the page found by URLSegment,
            // look for a translation and redirect (see #5001). Only happens on the last child in
            // a potentially nested URL chain.
            if (class_exists('Translatable')) {
                $locale = $request->getVar('locale');
                if ($locale
                    && i18n::getData()->validate($locale)
                    && $this->dataRecord
                    && $this->dataRecord->Locale != $locale
                ) {
                    $translation = $this->dataRecord->getTranslation($locale);
                    if ($translation) {
                        $response = new HTTPResponse();
                        $response->redirect($translation->Link(), 301);
                        throw new HTTPResponse_Exception($response);
                    }
                }
            }

            Director::set_current_page($this->data());

            try {
                $response = parent::handleRequest($request, $model);

                Director::set_current_page(null);
            } catch (HTTPResponse_Exception $e) {
                $this->popCurrent();

                Director::set_current_page(null);

                throw $e;
            }
        }

        return $response;
    }

    /**
     * Get the project name
     *
     * @return string
     */
    public function project()
    {
        global $project;
        return $project;
    }

    /**
     * Returns the associated database record
     */
    public function data()
    {
        return $this->dataRecord;
    }

    /*--------------------------------------------------------------------------------*/

    /**
     * Returns a fixed navigation menu of the given level.
     * @param int $level Menu level to return.
     * @return ArrayList
     */
    public function getMenu($level = 1)
    {
        if ($level == 1) {
            $result = SiteTree::get()->filter(array(
                "ShowInMenus" => 1,
                "ParentID" => 0
            ));
        } else {
            $parent = $this->data();
            $stack = array($parent);

            if ($parent) {
                while (($parent = $parent->Parent()) && $parent->exists()) {
                    array_unshift($stack, $parent);
                }
            }

            if (isset($stack[$level-2])) {
                $result = $stack[$level-2]->Children();
            }
        }

        $visible = array();

        // Remove all entries the can not be viewed by the current user
        // We might need to create a show in menu permission
        if (isset($result)) {
            foreach ($result as $page) {
                if ($page->canView()) {
                    $visible[] = $page;
                }
            }
        }

        return new ArrayList($visible);
    }

    public function Menu($level)
    {
        return $this->getMenu($level);
    }

    /**
     * Returns the default log-in form.
     *
     * @todo Check if here should be returned just the default log-in form or
     *       all available log-in forms (also OpenID...)
     */
    public function LoginForm()
    {
        return MemberAuthenticator::singleton()->loginForm($this);
    }

    public function SilverStripeNavigator()
    {
        $member = Security::getCurrentUser();
        $items = '';
        $message = '';

        if (Director::isDev() || Permission::check('CMS_ACCESS_CMSMain') || Permission::check('VIEW_DRAFT_CONTENT')) {
            if ($this->dataRecord) {
                Requirements::css(CMS_DIR . '/client/dist/styles/SilverStripeNavigator.css');
                Requirements::javascript(ADMIN_THIRDPARTY_DIR . '/jquery/jquery.js');
                Requirements::javascript(CMS_DIR . '/client/dist/js/SilverStripeNavigator.js');

                $return = $nav = SilverStripeNavigator::get_for_record($this->dataRecord);
                $items = $return['items'];
                $message = $return['message'];
            }

            if ($member) {
                $firstname = Convert::raw2xml($member->FirstName);
                $surname = Convert::raw2xml($member->Surname);
                $logInMessage = _t('SilverStripe\\CMS\\Controllers\\ContentController.LOGGEDINAS', 'Logged in as') ." {$firstname} {$surname} - <a href=\"Security/logout\">". _t('SilverStripe\\CMS\\Controllers\\ContentController.LOGOUT', 'Log out'). "</a>";
            } else {
                $logInMessage = sprintf(
                    '%s - <a href="%s">%s</a>',
                    _t('SilverStripe\\CMS\\Controllers\\ContentController.NOTLOGGEDIN', 'Not logged in'),
                    Security::config()->login_url,
                    _t('SilverStripe\\CMS\\Controllers\\ContentController.LOGIN', 'Login') ."</a>"
                );
            }
            $viewPageIn = _t('SilverStripe\\CMS\\Controllers\\ContentController.VIEWPAGEIN', 'View Page in:');

            return <<<HTML
				<div id="SilverStripeNavigator">
					<div class="holder">
					<div id="logInStatus">
						$logInMessage
					</div>

					<div id="switchView" class="bottomTabs">
						$viewPageIn
						$items
					</div>
					</div>
				</div>
					$message
HTML;

        // On live sites we should still see the archived message
        } else {
            if ($date = Versioned::current_archived_date()) {
                Requirements::css(CMS_DIR . '/client/dist/styles/SilverStripeNavigator.css');
                /** @var DBDatetime $dateObj */
                $dateObj = DBField::create_field('Datetime', $date);
                // $dateObj->setVal($date);
                return "<div id=\"SilverStripeNavigatorMessage\">" .
                    _t('SilverStripe\\CMS\\Controllers\\ContentController.ARCHIVEDSITEFROM', 'Archived site from') .
                    "<br>" . $dateObj->Nice() . "</div>";
            }
        }
        return null;
    }

    public function SiteConfig()
    {
        if (method_exists($this->dataRecord, 'getSiteConfig')) {
            return $this->dataRecord->getSiteConfig();
        } else {
            return SiteConfig::current_site_config();
        }
    }

    /**
     * Returns an RFC1766 compliant locale string, e.g. 'fr-CA'.
     * Inspects the associated {@link dataRecord} for a {@link SiteTree->Locale} value if present,
     * and falls back to {@link Translatable::get_current_locale()} or {@link i18n::default_locale()},
     * depending if Translatable is enabled.
     *
     * Suitable for insertion into lang= and xml:lang=
     * attributes in HTML or XHTML output.
     *
     * @return string
     */
    public function ContentLocale()
    {
        if ($this->dataRecord && $this->dataRecord->hasExtension('Translatable')) {
            $locale = $this->dataRecord->Locale;
        } elseif (class_exists('Translatable') && SiteTree::has_extension('Translatable')) {
            $locale = Translatable::get_current_locale();
        } else {
            $locale = i18n::get_locale();
        }

        return i18n::convert_rfc1766($locale);
    }


    /**
     * Return an SSViewer object to render the template for the current page.
     *
     * @param $action string
     *
     * @return SSViewer
     */
    public function getViewer($action)
    {
        // Manually set templates should be dealt with by Controller::getViewer()
        if (isset($this->templates[$action]) && $this->templates[$action]
            || (isset($this->templates['index']) && $this->templates['index'])
            || $this->template
        ) {
            return parent::getViewer($action);
        }

        // Prepare action for template search
        if ($action == "index") {
            $action = "";
        } else {
            $action = '_' . $action;
        }

        $templates = array_merge(
            // Find templates by dataRecord
            SSViewer::get_templates_by_class(get_class($this->dataRecord), $action, "SilverStripe\\CMS\\Model\\SiteTree"),
            // Next, we need to add templates for all controllers
            SSViewer::get_templates_by_class(static::class, $action, "SilverStripe\\Control\\Controller"),
            // Fail-over to the same for the "index" action
            SSViewer::get_templates_by_class(get_class($this->dataRecord), "", "SilverStripe\\CMS\\Model\\SiteTree"),
            SSViewer::get_templates_by_class(static::class, "", "SilverStripe\\Control\\Controller")
        );

        return new SSViewer($templates);
    }


    /**
     * This action is called by the installation system
     */
    public function successfullyinstalled()
    {
        // Return 410 Gone if this site is not actually a fresh installation
        if (!file_exists(BASE_PATH . '/install.php')) {
            $this->httpError(410);
        }

        // TODO Allow this to work when allow_url_fopen=0
        if (isset($_SESSION['StatsID']) && $_SESSION['StatsID']) {
            $url = 'http://ss2stat.silverstripe.com/Installation/installed?ID=' . $_SESSION['StatsID'];
            @file_get_contents($url);
        }

        global $project;
        $data = new ArrayData(array(
            'Project' => Convert::raw2xml($project),
            'Username' => Convert::raw2xml(Session::get('username')),
            'Password' => Convert::raw2xml(Session::get('password')),
        ));

        return array(
            "Title" =>  _t("SilverStripe\\CMS\\Controllers\\ContentController.INSTALL_SUCCESS", "Installation Successful!"),
            "Content" => $data->renderWith([
                'type' => 'Includes',
                'Install_successfullyinstalled'
            ]),
        );
    }

    public function deleteinstallfiles()
    {
        if (!Permission::check("ADMIN")) {
            return Security::permissionFailure($this);
        }

        $title = new DBVarchar("Title");
        $content = new DBHTMLText('Content');

        // We can't delete index.php as it might be necessary for URL routing without mod_rewrite.
        // There's no safe way to detect usage of mod_rewrite across webservers,
        // so we have to assume the file is required.
        $installfiles = array(
            'install.php',
            'config-form.css',
            'config-form.html',
            'index.html'
        );

        $unsuccessful = new ArrayList();
        foreach ($installfiles as $installfile) {
            if (file_exists(BASE_PATH . '/' . $installfile)) {
                @unlink(BASE_PATH . '/' . $installfile);
            }

            if (file_exists(BASE_PATH . '/' . $installfile)) {
                $unsuccessful->push(new ArrayData(array('File' => $installfile)));
            }
        }

        $data = new ArrayData(array(
            'Username' => Convert::raw2xml(Session::get('username')),
            'Password' => Convert::raw2xml(Session::get('password')),
            'UnsuccessfulFiles' => $unsuccessful
        ));
        $content->setValue($data->renderWith([
            'type' => 'Includes',
            'Install_deleteinstallfiles'
        ]));

        return array(
            "Title" => $title,
            "Content" => $content,
        );
    }
}

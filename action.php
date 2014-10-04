<?php
/**
 * DokuWiki Plugin oauth (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <andi@splitbrain.org>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class action_plugin_oauth extends DokuWiki_Action_Plugin {

    /**
     * Registers a callback function for a given event
     *
     * @param Doku_Event_Handler $controller DokuWiki's event controller object
     * @return void
     */
    public function register(Doku_Event_Handler $controller) {
        global $conf;
        if($conf['authtype'] != 'oauth') return;

        $conf['profileconfirm'] = false; // password confirmation doesn't work with oauth only users

        $controller->register_hook('DOKUWIKI_STARTED', 'BEFORE', $this, 'handle_start');
        $controller->register_hook('HTML_LOGINFORM_OUTPUT', 'BEFORE', $this, 'handle_loginform');
        $controller->register_hook('HTML_UPDATEPROFILEFORM_OUTPUT', 'BEFORE', $this, 'handle_profileform');
        $controller->register_hook('AUTH_USER_CHANGE', 'BEFORE', $this, 'handle_usermod');
        $controller->register_hook('AUTH_ACL_CHECK', 'AFTER', $this, 'handle_after_auth_acl_check');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'logoutconvenience');
    }
    public function logoutconvenience(&$event,$param) {
        global $ID;
        global $ACT;
        global $INFO;
        global $conf;
        //handle logout
        if($ACT=='logout'){
            $lockedby = checklock($ID); //page still locked?
            if($lockedby == $_SERVER['REMOTE_USER']) {
                unlock($ID); //try to unlock
            }
            $conf_key = strtolower($_SESSION[DOKU_COOKIE]['auth']['oauth'])."-logouturl";
            auth_logoff();
            $ID='start';
            $INFO = pageinfo();
            $ACT = 'show';

            if($this->getConf($conf_key) != ''){
                header('Location: '.$this->getConf($conf_key));
            }
        }
    }

    public function handle_after_auth_acl_check(Doku_Event &$event, $param) {
        global $ID;
        global $conf;
        global $USERINFO;
        global $ACT;
        if($event->result == 0 && $ACT=='show') {
            // check if logged in by oauth previousely

            if(isset($_COOKIE['oauth-autologin']) && $_COOKIE['oauth-autologin']!='') {
                /** @var helper_plugin_oauth $hlp */
                $hlp         = plugin_load('helper', 'oauth');
                $servicename = $_COOKIE['oauth-autologin'];
                $service     = $hlp->loadService($servicename);
                if(is_null($service)) return;

                // remember service in session
                session_start();
                $_SESSION[DOKU_COOKIE]['oauth-inprogress']['service'] = $servicename;
                $_SESSION[DOKU_COOKIE]['oauth-inprogress']['return-to'] = wl($ID,'',true);
                $_SESSION[DOKU_COOKIE]['oauth-inprogress']['id']      = $ID;
                session_write_close();

                $service->login();
            }
        }
    }

    /**
     * Start an oAuth login
     *
     * @param Doku_Event $event  event object by reference
     * @param mixed      $param  [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     * @return void
     */
    public function handle_start(Doku_Event &$event, $param) {
        global $INPUT;
        global $ID;

        /** @var helper_plugin_oauth $hlp */
        $hlp         = plugin_load('helper', 'oauth');
        $servicename = $INPUT->str('oauthlogin');
        $service     = $hlp->loadService($servicename);
        if(is_null($service)) return;

        // remember service in session
        session_start();
        $_SESSION[DOKU_COOKIE]['oauth-inprogress']['service'] = $servicename;
        $_SESSION[DOKU_COOKIE]['oauth-inprogress']['id']      = $ID;
        session_write_close();

        $service->login();
    }

    /**
     * Save groups for all the services a user has enabled
     *
     * @param Doku_Event $event  event object by reference
     * @param mixed      $param  [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     * @return void
     */
    public function handle_usermod(Doku_Event &$event, $param) {
        global $ACT;
        global $USERINFO;
        global $auth;
        global $INPUT;

        if($event->data['type'] != 'modify') return;
        if($ACT != 'profile') return;

        // we want to modify the user's groups
        $groups = $USERINFO['grps']; //current groups
        if(isset($event->data['params'][1]['grps'])) {
            // something already defined new groups
            $groups = $event->data['params'][1]['grps'];
        }

        /** @var helper_plugin_oauth $hlp */
        $hlp = plugin_load('helper', 'oauth');

        // get enabled and configured services
        $enabled  = $INPUT->arr('oauth_group');
        $services = $hlp->listServices();
        $services = array_map(array($auth, 'cleanGroup'), $services);

        // add all enabled services as group, remove all disabled services
        foreach($services as $service) {
            if(isset($enabled[$service])) {
                $groups[] = $service;
            } else {
                $idx = array_search($service, $groups);
                if($idx !== false) unset($groups[$idx]);
            }
        }
        $groups = array_unique($groups);

        // add new group array to event data
        $event->data['params'][1]['grps'] = $groups;

    }

    /**
     * Add service selection to user profile
     *
     * @param Doku_Event $event  event object by reference
     * @param mixed      $param  [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     * @return void
     */
    public function handle_profileform(Doku_Event &$event, $param) {
        global $USERINFO;
        /** @var auth_plugin_authplain $auth */
        global $auth;

        /** @var helper_plugin_oauth $hlp */
        $hlp = plugin_load('helper', 'oauth');

        /** @var Doku_Form $form */
        $form =& $event->data;
        $pos  = $form->findElementByAttribute('type', 'submit');

        $services = $hlp->listServices();
        if(!$services) return;

        $form->insertElement($pos, form_closefieldset());
        $form->insertElement(++$pos, form_openfieldset(array('_legend' => $this->getLang('loginwith'), 'class' => 'plugin_oauth')));
        foreach($services as $service) {
            $group = $auth->cleanGroup($service);
            $elem  = form_makeCheckboxField(
                'oauth_group['.$group.']',
                1, $service, '', 'simple',
                array(
                    'checked' => (in_array($group, $USERINFO['grps'])) ? 'checked' : ''
                )
            );

            $form->insertElement(++$pos, $elem);
        }
        $form->insertElement(++$pos, form_closefieldset());
        $form->insertElement(++$pos, form_openfieldset(array()));
    }

    /**
     * Add the oAuth login links
     *
     * @param Doku_Event $event  event object by reference
     * @param mixed      $param  [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     * @return void
     */
    public function handle_loginform(Doku_Event &$event, $param) {
        global $ID;

        /** @var helper_plugin_oauth $hlp */
        $hlp = plugin_load('helper', 'oauth');

        $html = '';
        foreach($hlp->listServices() as $service) {
            $html .= '<a href="'.wl($ID, array('oauthlogin' => $service)).'" class="plugin_oauth_'.$service.'">';
            $html .= $service;
            $html .= '</a> ';
        }
        if(!$html) return;

        /** @var Doku_Form $form */
        $form =& $event->data;
        $pos  = $form->findElementByType('closefieldset');

        $form->insertElement(++$pos, form_openfieldset(array('_legend' => $this->getLang('loginwith'), 'class' => 'plugin_oauth')));
        $form->insertElement(++$pos, $html);
        $form->insertElement(++$pos, form_closefieldset());
    }

}
// vim:ts=4:sw=4:et:

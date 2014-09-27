<?php
/**
 * DokuWiki Plugin oauth (Auth Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <andi@splitbrain.org>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();
include_once(DOKU_INC.'lib/plugins/authmysql/auth.php');

class auth_plugin_oauth extends auth_plugin_authplain {

    /**
     * Constructor
     *
     * Sets capabilities.
     */
    public function __construct() {
        parent::__construct();

        $this->cando['external'] = true;
    }

    /**
     * Handle the login
     *
     * This either trusts the session data (if any), processes the second oAuth step or simply
     * executes a normal plugin against local users.
     *
     * @param string $user
     * @param string $pass
     * @param bool   $sticky
     * @return bool
     */
    function trustExternal($user, $pass, $sticky = false) {
        global $conf;
        global $USERINFO;

        // are we in login progress?
        if(isset($_SESSION[DOKU_COOKIE]['oauth-inprogress'])) {
            $servicename = $_SESSION[DOKU_COOKIE]['oauth-inprogress']['service'];
            $page        = $_SESSION[DOKU_COOKIE]['oauth-inprogress']['id'];

            unset($_SESSION[DOKU_COOKIE]['oauth-inprogress']);
        }

        // check session for existing oAuth login data
        $session = $_SESSION[DOKU_COOKIE]['auth'];
        if(!isset($servicename) && isset($session['oauth'])) {
            $servicename = $session['oauth'];
            // check if session data is still considered valid
            if(($session['time'] >= time() - $conf['auth_security_timeout']))
            // removed browser uuid verification
             {

                $_SERVER['REMOTE_USER'] = $session['user'];
                $USERINFO               = $session['info'];

                return true;
            }
        }

        // either we're in oauth login or a previous log needs to be rechecked
        if(isset($servicename)) {
            /** @var helper_plugin_oauth $hlp */
            $hlp     = plugin_load('helper', 'oauth');
            $service = $hlp->loadService($servicename);
            if(is_null($service)) return false;

            // get the token
            if($service->checkToken()) {
                $uinfo = $service->getUser();
                $uinfo = $service->getAdditionalUserData($uinfo,$conf);

                $uinfo['user'] = $this->cleanUser((string) $uinfo['user']);
                if(!$uinfo['name']) $uinfo['name'] = $uinfo['user'];

                if(!$uinfo['user'] || !$uinfo['mail']) {
                    msg("$servicename did not provide the needed user info. Can't log you in", -1);
                    return false;
                }

                // see if the user is known already
                // $user = $this->getUserByEmail($uinfo['mail']);
                // if($user) {
                //     $sinfo = $this->getUserData($user);
                //     // check if the user allowed access via this service
                //     if(!in_array($this->cleanGroup($servicename), $sinfo['grps'])) {
                //         msg(sprintf($this->getLang('authnotenabled'), $servicename), -1);
                //         return false;
                //     }
                //     $uinfo['user'] = $user;
                //     $uinfo['name'] = $sinfo['name'];
                //     $uinfo['grps'] = array_merge((array) $uinfo['grps'], $sinfo['grps']);
                // } else {
                //     // new user, create him - making sure the login is unique by adding a number if needed
                //     $user  = $uinfo['user'];
                //     $count = '';
                //     while($this->getUserData($user . $count)) {
                //         if($count) {
                //             $count++;
                //         } else {
                //             $count = 1;
                //         }
                //     }
                //     $user            = $user . $count;
                //     $uinfo['user']   = $user;
                $uinfo['grps']   = (array) $uinfo['grps'];
                $uinfo['grps'][] = $conf['defaultgroup'];
                $uinfo['grps'][] = $this->cleanGroup($servicename); // add service as group

                //     //FIXME we should call trigger_user_mod?
                //     $ok = $this->createUser($user, auth_pwgen($user), $uinfo['name'], $uinfo['mail'], $uinfo['grps']);
                //     if(!$ok) {
                //         msg('something went wrong creating your user account. please try again later.', -1);
                //         return false;
                //     }

                //     // send notification about the new user
                //     $subscription = new Subscription();
                //     $subscription->send_register($user, $uinfo['name'], $uinfo['mail']);
                //}

                // set cookie for autologin
                $cookieDir = empty($conf['cookiedir']) ? DOKU_REL : $conf['cookiedir'];
                setcookie('oauth-autologin', $servicename, time()+60*60*24*365, $cookieDir, '', ($conf['securecookie'] && is_ssl()), true);

                // set user session
                $this->setUserSession($uinfo, $servicename);
                return true;
            }

            return false; // something went wrong during oAuth login
        }

        $authMysql = new auth_plugin_authmysql;
        if($authMysql->checkPass($user,$pass)) {
            $uinfo = $authMysql->getUserData($user);
            $uinfo['user'] = $user;
            $uinfo['grps']   = (array) $uinfo['grps'];
            $uinfo['grps'][] = $conf['defaultgroup'];
            $this->setUserSession($uinfo, 'mysql');
            return true;
        } elseif($user !='' || $pass !='') {
            msg($this->getLang('wrong_password'), -1);
            return false;
        } else {
            return false;
        }
    }

    /**
     * @param array  $data
     * @param string $service
     */
    protected function setUserSession($data, $service) {
        global $USERINFO;
        global $conf;

        // set up groups
        if(!is_array($data['grps'])) {
            $data['grps'] = array();
        }
        $data['grps'][] = $this->cleanGroup($service);
        $data['grps']   = array_unique($data['grps']);

        $USERINFO                               = $data;
        $_SERVER['REMOTE_USER']                 = $data['user'];
        $_SESSION[DOKU_COOKIE]['auth']['user']  = $data['user'];
        $_SESSION[DOKU_COOKIE]['auth']['pass']  = $data['pass'];
        $_SESSION[DOKU_COOKIE]['auth']['info']  = $USERINFO;
        $_SESSION[DOKU_COOKIE]['auth']['buid']  = auth_browseruid();
        $_SESSION[DOKU_COOKIE]['auth']['time']  = time();
        $_SESSION[DOKU_COOKIE]['auth']['oauth'] = $service;
    }

    /**
     * Unset additional stuff in session on logout
     */
    public function logOff() {
        parent::logOff();

        if(isset($_SESSION[DOKU_COOKIE]['auth']['buid'])) {
            unset($_SESSION[DOKU_COOKIE]['auth']['buid']);
        }
        if(isset($_SESSION[DOKU_COOKIE]['auth']['time'])) {
            unset($_SESSION[DOKU_COOKIE]['auth']['time']);
        }
        if(isset($_SESSION[DOKU_COOKIE]['auth']['oauth'])) {
            unset($_SESSION[DOKU_COOKIE]['auth']['oauth']);
        }
    }

    /**
     * Find a user by his email address
     *
     * @param $mail
     * @return bool|string
     */
    protected function getUserByEmail($mail) {
        if($this->users === null) $this->_loadUserData();
        $mail = strtolower($mail);

        foreach($this->users as $user => $uinfo) {
            if(strtolower($uinfo['mail']) == $mail) return $user;
        }

        return false;
    }

    /**
     * Enhance function to check aainst duplicate emails
     *
     * @param string $user
     * @param string $pwd
     * @param string $name
     * @param string $mail
     * @param null   $grps
     * @return bool|null|string
     */
    public function createUser($user, $pwd, $name, $mail, $grps = null) {
        if($this->getUserByEmail($mail)) {
            msg($this->getLang('emailduplicate'), -1);
            return false;
        }

        return parent::createUser($user, $pwd, $name, $mail, $grps);
    }

    /**
     * Enhance function to check aainst duplicate emails
     *
     * @param string $user
     * @param array  $changes
     * @return bool
     */
    public function modifyUser($user, $changes) {
        global $conf;

        if(isset($changes['mail'])) {
            $found = $this->getUserByEmail($changes['mail']);
            if($found != $user) {
                msg($this->getLang('emailduplicate'), -1);
                return false;
            }
        }

        $ok = parent::modifyUser($user, $changes);

        // refresh session cache
        touch($conf['cachedir'] . '/sessionpurge');

        return $ok;
    }

}

// vim:ts=4:sw=4:et:
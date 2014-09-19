<?php

namespace OAuth\Plugin;

/**
 * Class DoorkeeperAdapter
 *
 * This is an example on how to implement your own adapter for making DokuWiki login against
 * a custom oAuth provider. The used Generic Service backend expects the authorization and
 * token endpoints to be configured in the DokuWiki backend.
 *
 * Your custom API to access user data has to be implemented in the getUser function. The one here
 * is setup to work with the demo setup of the "Doorkeeper" ruby gem.
 *
 * @link https://github.com/doorkeeper-gem/doorkeeper
 * @package OAuth\Plugin
 */
class InternalAdapter extends AbstractAdapter {

    /**
     * Retrieve the user's data
     *
     * The array needs to contain at least 'user', 'mail', 'name' and optional 'grps'
     *
     * @return array
     */
    public function getUser() {
        $JSON = new \JSON(JSON_LOOSE_TYPE);
        $data = array();

        /** var OAuth\OAuth2\Service\Generic $this->oAuth */
        $result1 = $JSON->decode($this->oAuth->request('https://accounts.iiet.pl/appapi/v1/students/me'));

        $data['user_id'] = $result1['user_id'];
        

        return $data;
    }

    public function getAdditionalUserData($data,$conf) {
        $JSON = new \JSON(JSON_LOOSE_TYPE);

        $result2 = $JSON->decode($this->oAuth->request($conf['plugin']['oauth']['internal-api-endpoint'].'/'.$data['user_id'].'?aid='.$conf['plugin']['oauth']['internal-api-aid'].'&authentication_token='.$conf['plugin']['oauth']['internal-api-token']));

        $data['user'] = $result2['login'];
        $data['name'] = $result2['name'];
        $data['mail'] = $result2['email'];
        $data['grps'] = $result2['group_names'];
        return $data;
    }

    /**
     * We make use of the "Generic" oAuth 2 Service as defined in
     * phpoauthlib/src/OAuth/OAuth2/Service/Generic.php
     *
     * @return string
     */
    public function getServiceName() {
        return 'Generic';
    }

}
<?php
/**
 * Options for the oauth plugin
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 */

class setting_plugin_oauth extends setting {

    function update($input) {
        return true;
    }

    public function html(&$plugin, $echo = false) {
        /** @var helper_plugin_oauth $hlp */
        $hlp = plugin_load('helper', 'oauth');

        $key   = htmlspecialchars($this->_key);
        $value = '<code>'.$hlp->redirectURI().'</code>';

        $label = '<label for="config___'.$key.'">'.$this->prompt($plugin).'</label>';
        $input = '<div>'.$value.'</div>';
        return array($label, $input);
    }

}

$meta['info']                = array('plugin_oauth');
$meta['internal-key']      = array('string');
$meta['internal-secret']   = array('string');
$meta['internal-authurl']  = array('string');
$meta['internal-tokenurl'] = array('string');

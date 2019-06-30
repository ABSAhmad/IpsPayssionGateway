//<?php

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    exit;
}

class payssion_hook_RegisterGatewayHook extends _HOOK_CLASS_
{
    /**
     * @return  array
     */
    public static function gateways()
    {
        $gateways = parent::gateways();

        $gateways['Payssion'] = 'IPS\payssion\Payssion';

        return $gateways;
    }
}

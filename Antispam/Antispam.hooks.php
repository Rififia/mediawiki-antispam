<?php

class CTHooks {

    /**
     * Upload spam test 
     * UploadBase $upload
     * string $mime
     * bool|array $error
     * @return none
     */
    public static function onUploadFilter ( $upload, $mime, &$error ) {
        global $wgCTAccessKey, $wgCTServerURL, $wgRequest, $wgCTAgent, $wgCTExtName, $wgCTNewEditsOnly, $wgCTMinEditCount, $wgUser;
        
        # Skip spam check if error exists already
        if ($error !== TRUE) {
            return;
        }
        
        $allowUpload = true;

        // Skip antispam test if user is member of special group
        if ( $wgUser->isAllowed('cleantalk-bypass') ) {
            return;
        }

        // Skip antispam test for user with getEditCount() more than setted value
        $edit_count = $wgUser->getEditCount();
        if ( isset($edit_count) && $edit_count > $wgCTMinEditCount ) {
            return;
        }

        // Check
        $ctResult = CTBody::onSpamCheck(
            'check_message', array(
                'message' => $wgRequest->getVal('wpUploadDescription'),
                'sender_email' => $wgUser->mEmail,
                'sender_nickname' => $wgUser->mName,
            )
        );
        if ( $ctResult->errno != 0 ) {
            if(CTBody::JSTest() != 1)
            {
                $ctResult->allow = 0;
                $ctResult->comment = "Forbidden. Please, enable Javascript.";
                $allowUpload = false;
            }
            else
            {
                $ctResult->allow = 1;
                $allowUpload = true;
            }
        }

        // Disallow edit with CleanTalk comment 
        if (!$allowUpload || $ctResult->allow == 0) {
            // Converting links to wikitext format
            $errorText = preg_replace("(<a\shref=\"([^\s]+)\".+>([a-f0-9]+)</a>)", "[$1 $2]", $ctResult->comment);

            // Fill $error parameter as array with error text
            $error = array($errorText);
        }
       
        if ($ctResult->inactive === 1) {
            CTBody::SendAdminEmail( $wgCTExtName, $ctResult->comment );
        }

        return;
    }
    
    /**
     * Edit spam test 
     * @return bool
     */
    public static function onEditFilter (  $editor, $text, $section, &$error, $summary ) {
        global $wgCTAccessKey, $wgCTServerURL, $wgRequest, $wgCTAgent, $wgCTExtName, $wgCTNewEditsOnly, $wgCTMinEditCount;
        
        $allowEdit = true;

        // Skip antispam test if editor member of special group
        if ( $editor->getArticle()->getContext()->getUser()->isAllowed('cleantalk-bypass') ) {
            return $allowEdit;
        }
        
        // Skip antispam test of not new edit if flag is set
        if ( $wgCTNewEditsOnly && !$editor->isNew) {
            return $allowEdit;
        }

        // Skip antispam test for user with getEditCount() more than setted value
        $edit_count = $editor->getArticle()->getContext()->getUser()->getEditCount();
        if ( isset($edit_count) && $edit_count > $wgCTMinEditCount ) {
            return $allowEdit;
        }

        // Check
        $ctResult = CTBody::onSpamCheck(
            'check_message', array(
                'message' => $editor->getTitle()->getText() . "\n \n" . $summary . "\n \n" . $text,
                'sender_email' => $editor->getArticle()->getContext()->getUser()->mEmail,
                'sender_nickname' => $editor->getArticle()->getContext()->getUser()->mName,
            )
        );

        // Allow edit if we have any API errors
        /*if ( $ctResult->errno != 0 ) {
            return $allowEdit;
        }*/
        if ( $ctResult->errno != 0 ) 
        {
            if(CTBody::JSTest()!=1)
            {
                $ctResult->allow=0;
                $ctResult->comment = "Forbidden. Please, enable Javascript.";
                $allowEdit = false;
            }
            else
            {
                $ctResult->allow=1;
                $allowEdit = true;
            }
        }

        // Disallow edit with CleanTalk comment 
        if (!$allowEdit || $ctResult->allow == 0) {
            $error = $ctResult->comment;
            
            // Converting links to wikitext format
            $error = preg_replace("(<a\shref=\"([^\s]+)\".+>([a-f0-9]+)</a>)", "[$1 $2]", $error);
            
            $error = Html::openElement( 'div', array( 'class' => 'errorbox' ) ) .
               $error . 
               Html::closeElement( 'div' ) . "\n" .
               Html::element( 'br', array( 'clear' => 'all' ) ) . "\n";
        }
       
        if ($ctResult->inactive === 1) {
            CTBody::SendAdminEmail( $wgCTExtName, $ctResult->comment );
        }

        return $allowEdit;
    }
    
    /**
     * Account spam test 
     * @return bool
     */
    public static function onAbortNewAccount ( $user, &$message ) {
        global $wgCTAccessKey, $wgCTServerURL, $wgRequest, $wgCTAgent, $wgCTExtName;
        
        $allowAccount = true;

        // Check
        $ctResult = CTBody::onSpamCheck(
            'check_newuser', array(
                'sender_email' => $user->mEmail,
                'sender_nickname' => $user->mName,
            )
        );
        // Allow account if we have any API errors
        if ( $ctResult->errno != 0 ) 
        {
            if(CTBody::JSTest()!=1)
            {
                $ctResult->allow=0;
                $ctResult->comment = "Forbidden. Please, enable Javascript.";
            }
            else
            {
                $ctResult->allow=1;
            }
        }

        // Disallow account with CleanTalk comment 
        if ($ctResult->allow == 0) {
            $allowAccount = false;
            $message = $ctResult->comment;
        }
        
        if ($ctResult->inactive === 1) {
            CTBody::SendAdminEmail( $wgCTExtName, $ctResult->comment );
        }

        return $allowAccount;
    }
    
    public static function onSkinAfterBottomScripts( $skin, &$text )
    {
        global $wgCTShowLink, $wgCTSFW, $wgCTDataStoreFile, $wgCTAccessKey;

        $text .= CTBody::AddJSCode();
        CTBody::ctSetCookie();      

        /* SFW starts */
        
        if($wgCTSFW && file_exists($wgCTDataStoreFile))
        {
            $settings = file_get_contents($wgCTDataStoreFile);
            $sfw = new CleanTalkSFW();

            if ($settings)
            {
                $settings = json_decode($settings, true);
                CTBody::createSFWTables();
                if(!isset($settings['lastSFWUpdate']) || ($settings['lastSFWUpdate'] && (time()-$settings['lastSFWUpdate'] > 86400)))
                {                   
                    $sfw->sfw_update($wgCTAccessKey);
                    $settings['lastSFWUpdate'] = time();                                       
                }
                if (!isset($settings['lastSFWSendLogs']) || $settings['lastSFWSendLogs'] && (time() - $settings['lastSFWSendLogs'] > 3600))
                {
                    $sfw->send_logs($wgCTAccessKey); 
                    $settings['lastSFWSendLogs'] = time();                                        
                }
                $fp = fopen( $wgCTDataStoreFile, 'w' ) or error_log( 'Could not open file:' . $wgCTDataStoreFile );
                fwrite( $fp, json_encode($settings) );
                fclose( $fp );              
                /* Check IP here */
                
                $is_sfw_check = true;
                $sfw->ip_array = (array)CleantalkSFW::ip_get(array('real'), true);  

                foreach($sfw->ip_array as $key => $value)
                {
                    if(isset($_COOKIE['apbct_sfw_pass_key']) && $_COOKIE['apbct_sfw_pass_key'] == md5($value . $wgCTAccessKey))
                    {
                        $is_sfw_check=false;
                        if(isset($_COOKIE['apbct_sfw_passed']))
                        {
                            @setcookie ('apbct_sfw_passed'); //Deleting cookie
                            $sfw->sfw_update_logs($value, 'passed');
                        }
                    }
                } unset($key, $value);  

                if($is_sfw_check)
                {
                    $sfw->check_ip();
                    if($sfw->result)
                    {
                        $sfw->sfw_update_logs($sfw->blocked_ip, 'blocked');
                        $sfw->sfw_die($wgCTAccessKey);
                    }
                }               
                /* Finish checking IP */
            }
        }
        
        /* SFW ends */
        
        if($wgCTShowLink)
        {
            $text.="<div style='width:100%;text-align:center;'><a href='https://cleantalk.org'>MediaWiki spam</a> blocked by CleanTalk.</div>";
        }
        return true;
    }
}
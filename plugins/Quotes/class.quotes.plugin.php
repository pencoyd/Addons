<?php if (!defined('APPLICATION')) exit();

/**
 * Quotes Plugin
 * 
 * This plugin allows users to quote comments for reference in their own comments
 * within a discussion.
 * 
 * Changes: 
 *  1.0     Initial release
 *  1.6.1   Overhaul
 *  1.6.4   Moved button to reactions area & changed js accordingly.
 * 
 * @author Tim Gunter <tim@vanillaforums.com>
 * @copyright 2003 Vanilla Forums, Inc
 * @license http://www.opensource.org/licenses/gpl-2.0.php GPL
 * @package Addons
 */

// Define the plugin:
$PluginInfo['Quotes'] = array(
   'Name' => 'Quotes',
   'Description' => "Adds an option to each comment for users to easily quote each other.",
   'Version' => '1.6.5',
   'MobileFriendly' => TRUE,
   'RequiredApplications' => array('Vanilla' => '2.1a'),
   'RequiredTheme' => FALSE, 
   'RequiredPlugins' => FALSE,
   'HasLocale' => TRUE,
   'RegisterPermissions' => FALSE,
   'Author' => "Tim Gunter",
   'AuthorEmail' => 'tim@vanillaforums.com',
   'AuthorUrl' => 'http://www.vanillaforums.com'
);

class QuotesPlugin extends Gdn_Plugin {
   
   public function __construct() {
      parent::__construct();
      
      if (function_exists('ValidateUsernameRegex'))
         $this->ValidateUsernameRegex = ValidateUsernameRegex();
      else
         $this->ValidateUsernameRegex = "[\d\w_]{3,20}";
      
      // Whether to handle drawing quotes or leave it up to some other plugin
      $this->RenderQuotes = C('Plugins.Quotes.RenderQuotes',TRUE);
   }

   public function ProfileController_AfterAddSideMenu_Handler($Sender) {
      if (!Gdn::Session()->CheckPermission('Garden.SignIn.Allow'))
         return;
   
      $SideMenu = $Sender->EventArguments['SideMenu'];
      $ViewingUserID = Gdn::Session()->UserID;
      
      if ($Sender->User->UserID == $ViewingUserID) {
         $SideMenu->AddLink('Options', Sprite('SpQuote').T('Quote Settings'), '/profile/quotes', FALSE, array('class' => 'Popup'));
      } else {
         $SideMenu->AddLink('Options', Sprite('SpQuote').T('Quote Settings'), UserUrl($Sender->User, '', 'quotes'), 'Garden.Users.Edit', array('class' => 'Popup'));
      }
   }
   
   public function ProfileController_Quotes_Create($Sender) {
      $Sender->Permission('Garden.SignIn.Allow');
      $Sender->Title("Quotes Settings");
      
      $Args = $Sender->RequestArgs;
      if (sizeof($Args) < 2)
         $Args = array_merge($Args, array(0,0));
      elseif (sizeof($Args) > 2)
         $Args = array_slice($Args, 0, 2);
      
      list($UserReference, $Username) = $Args;
      
      $Sender->GetUserInfo($UserReference, $Username);
      $UserPrefs = Gdn_Format::Unserialize($Sender->User->Preferences);
      if (!is_array($UserPrefs))
         $UserPrefs = array();
      
      $UserID = $ViewingUserID = Gdn::Session()->UserID;
      
      if ($Sender->User->UserID != $ViewingUserID) {
         $Sender->Permission('Garden.Users.Edit');
         $UserID = $Sender->User->UserID;
      }
      
      $Sender->SetData('ForceEditing', ($UserID == Gdn::Session()->UserID) ? FALSE : $Sender->User->Name);
      $QuoteFolding = GetValue('Quotes.Folding', $UserPrefs, '1');
      $Sender->Form->SetValue('QuoteFolding', $QuoteFolding);
      
      $Sender->SetData('QuoteFoldingOptions', array(
         'None'   => "Don't ever fold quotes",
         '1'      => 'One level deep',
         '2'      => 'Two levels deep',
         '3'      => 'Three levels deep',
         '4'      => 'Four levels deep',
         '5'      => 'Five levels deep'
      ));
      
      // If seeing the form for the first time...
      if ($Sender->Form->IsPostBack()) {
         $NewFoldingLevel = $Sender->Form->GetValue('QuoteFolding', '1');
         if ($NewFoldingLevel != $QuoteFolding) {
            Gdn::UserModel()->SavePreference($UserID, 'Quotes.Folding', $NewFoldingLevel);
            $Sender->InformMessage(T("Your changes have been saved."));
         }
      }

      $Sender->Render('quotes','','plugins/Quotes');
   }
   
   public function DiscussionController_BeforeDiscussionRender_Handler($Sender) {
      if (!Gdn::Session()->IsValid())
         return;
      
      $UserPrefs = Gdn_Format::Unserialize(Gdn::Session()->User->Preferences);
      if (!is_array($UserPrefs))
         $UserPrefs = array();
      
      $QuoteFolding = GetValue('Quotes.Folding', $UserPrefs, '1');
      $Sender->AddDefinition('QuotesFolding', $QuoteFolding);
   }
   
   public function PluginController_Quotes_Create($Sender) {
		$this->Dispatch($Sender, $Sender->RequestArgs);
   }
   
   public function Controller_Getquote($Sender) {
      $this->DiscussionController_GetQuote_Create($Sender);
   }
   
   public function DiscussionController_GetQuote_Create($Sender, $Selector, $Format = FALSE) {
      $Sender->DeliveryMethod(DELIVERY_METHOD_JSON);
      $Sender->DeliveryType(DELIVERY_TYPE_VIEW);
      
      if (!$Format)
         $Format = C('Garden.InputFormatter');

      $QuoteData = array(
         'status' => 'failed'
      );
//      array_shift($Sender->RequestArgs);
//      if (sizeof($Sender->RequestArgs)) {
         $QuoteData['selector'] = $Selector;
         list($Type, $ID) = explode('_',$Selector);
         $this->FormatQuote($Type, $ID, $QuoteData, $Format);
//      }
      $Sender->SetJson('Quote', $QuoteData);
      $Sender->Render('GetQuote', '', 'plugins/Quotes');
   }

   public function DiscussionController_Render_Before($Sender) {
      $this->PrepareController($Sender);
   }
   
   public function PostController_Render_Before($Sender) {
      $this->PrepareController($Sender);
   }
   
   protected function PrepareController($Sender) {
      //if (!$this->RenderQuotes) return;
      $Sender->AddJsFile($this->GetResource('js/quotes.js', FALSE, FALSE));
//      $Sender->AddCssFile($this->GetResource('css/quotes.css', FALSE, FALSE));
   }
   
   /**
    * Add 'Quote' option to Discussion.
    */
   public function Base_AfterReactions_Handler($Sender, $Args) {
      $this->AddQuoteButton($Sender, $Args);
   }
   
   /**
    * Output Quote link.
    */
   protected function AddQuoteButton($Sender, $Args) {
      if (!Gdn::Session()->UserID) return;
      if (isset($Args['Comment'])) {
         $Object = $Args['Comment'];
         $ObjectID = 'Comment_'.$Args['Comment']->CommentID;
      } else if (isset($Args['Discussion'])) {
         $Object = $Args['Discussion'];
         $ObjectID = 'Discussion_'.$Args['Discussion']->DiscussionID;
      } else return;
      
      $Reply = T('Reply'); // help capture translation.
      $Types = GetValue('ReactionTypes', $Sender->EventArguments);
      if ($Types)
         echo Bullet();
      
      echo Anchor(Sprite('ReactQuote', 'ReactSprite').T('Quote'), Url("post/quote/{$Object->DiscussionID}/{$ObjectID}",TRUE), 'React Quote');
   }
   
   public function DiscussionController_BeforeCommentDisplay_Handler($Sender) {
      $this->RenderQuotes($Sender);
   }
   
   public function PostController_BeforeCommentDisplay_Handler($Sender) {
      $this->RenderQuotes($Sender);
   }
   
   protected function RenderQuotes($Sender) {
      if (!$this->RenderQuotes) return;
      
      static $ValidateUsernameRegex = NULL;
      
      if (is_null($ValidateUsernameRegex))
         $ValidateUsernameRegex = sprintf("[%s]+", 
            C('Garden.User.ValidationRegex',"\d\w_ "));
      
      switch ($Sender->EventArguments['Object']->Format) {
         case 'Html':
            $Sender->EventArguments['Object']->Body = preg_replace_callback("/(<blockquote\s+(?:class=\"(?:User)?Quote\")?\s+rel=\"([^\"]+)\">)/ui", array($this, 'QuoteAuthorCallback'), $Sender->EventArguments['Object']->Body);
            $Sender->EventArguments['Object']->Body = str_ireplace('</blockquote>','</p></div></blockquote>',$Sender->EventArguments['Object']->Body);
            break;
//         case 'Wysiwyg':
//            $Sender->EventArguments['Object']->Body = preg_replace_callback("/(<blockquote\s+(?:class=\"(?:User)?Quote\")?\s+rel=\"([^\"]+)\">)/ui", array($this, 'QuoteAuthorCallback'), $Sender->EventArguments['Object']->Body);
//            $Sender->EventArguments['Object']->Body = str_ireplace('</blockquote>','</p></div></blockquote>',$Sender->EventArguments['Object']->Body);
//            break;
         case 'Markdown':
            // BBCode quotes with authors
            $Sender->EventArguments['Object']->Body = preg_replace_callback("#(\[quote(\s+author)?=[\"']?(.*?)(\s+link.*?)?(;[\d]+)?[\"']?\])#usi", array($this, 'QuoteAuthorCallback'), $Sender->EventArguments['Object']->Body);

            // BBCode quotes without authors
            $Sender->EventArguments['Object']->Body = str_ireplace('[quote]','<blockquote class="UserQuote"><div class="QuoteText"><p>',$Sender->EventArguments['Object']->Body);
            
            // End of BBCode quotes
            $Sender->EventArguments['Object']->Body = str_ireplace('[/quote]','</p></div></blockquote>',$Sender->EventArguments['Object']->Body);
            break;
            
         case 'Display':
         case 'Text':
         default:
            break;
      
      }
   }
   
   protected function QuoteAuthorCallback($Matches) {
      $Attribution = T('%s said:');
      $Link = Anchor($Matches[2], '/profile/'.$Matches[2], '', array('rel' => 'nofollow'));
      $Attribution = sprintf($Attribution, $Link);
      return <<<BLOCKQUOTE
      <blockquote class="UserQuote"><div class="QuoteAuthor">{$Attribution}</div><div class="QuoteText"><p>
BLOCKQUOTE;
   }
   
   public function PostController_Quote_Create($Sender) {
      if (sizeof($Sender->RequestArgs) < 2) return;
      $Selector = $Sender->RequestArgs[1];
      $Sender->SetData('Plugin.Quotes.QuoteSource', $Selector);
      $Sender->View = 'comment';
      return $Sender->Comment();
   }
   
   public function PostController_BeforeCommentRender_Handler($Sender) {
      if (isset($Sender->Data['Plugin.Quotes.QuoteSource'])) {
         if (sizeof($Sender->RequestArgs) < 2) return;
         $Selector = $Sender->RequestArgs[1];
         list($Type, $ID) = explode('_', $Selector);
         $QuoteData = array(
            'status' => 'failed'
         );
         $this->FormatQuote($Type, $ID, $QuoteData);
         
         if ($QuoteData['status'] == 'success') {
            switch ($QuoteData['format']) {
               case 'Html':
                  $Sender->Form->SetValue('Body', '<blockquote rel="'.$QuoteData['authorname'].'">'.$QuoteData['body']."</blockquote>\n");
                  break;
               case 'BBCode':
                  $QuoteAuthor = $QuoteData['authorname'];
                  if (GetValue('type', $QuoteData) == 'comment')
                     $QuoteAuthor .= ";{$QuoteData['typeid']}";
                     
                  $Sender->Form->SetValue('Body', '[quote="'.$QuoteAuthor.'"]'.$QuoteData['body']."[/quote]\n");
                  break;
               case 'Display':
               case 'Text':
               default:
                  $Sender->Form->SetValue('Body', '> '.$QuoteData['authorname']."\n> {$QuoteData['body']}\n");
            }
         }
      }
   }
   
   protected function FormatQuote($Type, $ID, &$QuoteData, $Format = FALSE) {
      if (!$Format)
         $Format = C('Garden.InputFormatter');
      
      $Type = strtolower($Type);
      $Model = FALSE;
      switch ($Type) {
         case 'comment':
            $Model = new CommentModel();
            break;
         
         case 'discussion':
            $Model = new DiscussionModel();
            break;
            
         default:
            break;
      }
      
      //$QuoteData = array();
      if ($Model) {
         $Data = $Model->GetID($ID);
         $NewFormat = $Format;
         if ($NewFormat == 'Wysiwyg')
            $NewFormat = 'Html';
         $QuoteFormat = $Data->Format;
         if ($QuoteFormat == 'Wysiwyg')
            $QuoteFormat = 'Html';
         
         // Perform transcoding if possible
         $NewBody = $Data->Body;
         if ($QuoteFormat != $NewFormat) {
            if (in_array($NewFormat, array('Html', 'Wysiwyg')))
               $NewBody = Gdn_Format::To($NewBody, $QuoteFormat);
            elseif ($QuoteFormat == 'Html' && $NewFormat == 'BBCode')
               $NewBody = Gdn_Format::Text($NewBody);
            elseif ($QuoteFormat == 'Text' && $NewFormat == 'BBCode')
               $NewBody = Gdn_Format::Text($NewBody);
            else
               $NewBody = Gdn_Format::PlainText($NewBody, $QuoteFormat);
            
            if (!in_array($NewFormat, array('Html', 'Wysiwyg')))
               Gdn::Controller()->InformMessage(sprintf(T('The quote had to be converted from %s to %s.', 'The quote had to be converted from %s to %s. Some formatting may have been lost.'), $QuoteFormat, $NewFormat));
         }
         $Data->Body = $NewBody;
         
         // Format the quote according to the format.
         switch ($Format) {
            case 'Html':   // HTML
               $Quote = '<blockquote class="Quote" rel="'.htmlspecialchars($Data->InsertName).'">'.$Data->Body.'</blockquote>'."\n";
               break;
            
            case 'BBCode':
               $Author = htmlspecialchars($Data->InsertName);
               if ($ID)
                  $IDString = ';'.htmlspecialchars($ID);
               
               $QuoteBody = $Data->Body;
               
               // TODO: Strip inner quotes...
//                  $QuoteBody = trim(preg_replace('`(\[quote.*/quote\])`si', '', $QuoteBody));
               
               $Quote = <<<BQ
[quote="{$Author}{$IDString}"]{$QuoteBody}[/quote]

BQ;
               break;

            case 'Markdown':
            case 'Display':
            case 'Text':
               $QuoteBody = $Data->Body;
               
               // Strip inner quotes and mentions...
               $QuoteBody = self::_StripMarkdownQuotes($QuoteBody);
               $QuoteBody = self::_StripMentions($QuoteBody);
               
               $Quote = '> '.sprintf(T('%s said:'), '@'.$Data->InsertName)."\n".
                  '> '.str_replace("\n", "\n> ", $QuoteBody);
               
               break;
            case 'Wysiwyg':
               $Attribution = sprintf(T('%s said:'), UserAnchor($Data, NULL, array('Px' => 'Insert')));
               $QuoteBody = $Data->Body;

               // TODO: Strip inner quotes...
//                  $QuoteBody = trim(preg_replace('`(<blockquote.*/blockquote>)`si', '', $QuoteBody));

               $Quote = <<<BLOCKQUOTE
<blockquote class="Quote">
  <div class="QuoteAuthor">$Attribution</div>
  <div class="QuoteText">$QuoteBody</div>
</blockquote>

BLOCKQUOTE;
                  
                  break;
         }
         
         $QuoteData = array_merge($QuoteData, array(
            'status'       => 'success',
            'body'         => $Quote,
            'format'       => $Format,
            'authorid'     => $Data->InsertUserID,
            'authorname'   => $Data->InsertName,
            'type'         => $Type,
            'typeid'       => $ID
         ));
      }
   }
   
   public function Setup() {
      SaveToConfig('Garden.Html.SafeStyles', FALSE);
   }
   
   protected static function _StripMarkdownQuotes($Text) {
      $Text = preg_replace('/
			  (								# Wrap whole match in $1
				(?>
				  ^[ ]*>[ ]?			# ">" at the start of a line
					.+\n					# rest of the first line
				  (.+\n)*					# subsequent consecutive lines
				  \n*						# blanks
				)+
			  )
			/xm', '', $Text);
      
      return $Text;
   }
   
   protected static function _StripMentions($Text) {
      $Text = preg_replace(
            '/(^|[\s,\.>])@(\w{1,50})\b/i',
            '$1$2',
            $Text
         );
      
      return $Text;
   }
   
   public function OnDisable() {
      RemoveFromConfig('Garden.Html.SafeStyles');
   }
   
   public function Structure() {
      // Nothing to do here!
   }
         
}
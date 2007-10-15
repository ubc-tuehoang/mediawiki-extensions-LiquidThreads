<?php

/**
* @package MediaWiki
* @subpackage LiquidThreads
* @author David McCabe <davemccabe@gmail.com>
* @licence GPL2
*/

if( !defined( 'MEDIAWIKI' ) ) {
	echo( "This file is an extension to the MediaWiki software and cannot be used standalone.\n" );
	die( -1 );
}

function efVarDump($value) {
	global $wgOut;
	ob_start();
	var_dump($value);
	$tmp=ob_get_contents();
	ob_end_clean();
	$wgOut->addHTML(/*'<pre>' . htmlspecialchars($tmp,ENT_QUOTES) . '</pre>'*/ $tmp);
}

function efThreadTable($ts) {
	global $wgOut;
	$wgOut->addHTML('<table>');
	foreach($ts as $t)
		efThreadTableHelper($t, 0);
	$wgOut->addHTML('</table>');
}

function efThreadTableHelper($t, $indent) {
	global $wgOut;
	$wgOut->addHTML('<tr>');
	$wgOut->addHTML('<td>' . $indent);
	$wgOut->addHTML('<td>' . $t->id());
	$wgOut->addHTML('<td>' . $t->title()->getPrefixedText());
	$wgOut->addHTML('</tr>');
	foreach($t->subthreads() as $st)
		efThreadTableHelper($st, $indent + 1);
}

require_once('LqtModel.php');
require_once('Pager.php');
require_once('PageHistory.php');

$wgHooks['MediaWikiPerformAction'][] = 'LqtDispatch::tryPage';
$wgHooks['SpecialMovepageAfterMove'][] = 'LqtDispatch::onPageMove';
$wgHooks['LinkerMakeLinkObj'][] = 'LqtDispatch::makeLinkObj';
$wgHooks['SkinTemplateTabAction'][] = 'LqtDispatch::tabAction';
$wgHooks['ChangesListInsertArticleLink'][] = 'LqtDispatch::changesListArticleLink';
$wgHooks['SkinTemplateOutputPageBeforeExec'][] = 'LqtDispatch::setNewtalkHTML';

class LqtDispatch {
	public static $views = array(
		'TalkpageArchiveView' => 'TalkpageArchiveView',
		'TalkpageHeaderView' => 'TalkpageHeaderView',
		'TalkpageView' => 'TalkpageView',
		'ThreadHistoryListingView' => 'ThreadHistoryListingView',
		'ThreadHistoricalRevisionView' => 'ThreadHistoricalRevisionView',
		'IndividualThreadHistoryView' => 'IndividualThreadHistoryView',
		'ThreadDiffView' => 'ThreadDiffView',
		'ThreadPermalinkView' => 'ThreadPermalinkView',
		'ThreadProtectionFormView' => 'ThreadProtectionFormView'
		);

	static function talkpageMain(&$output, &$talk_article, &$title, &$user, &$request) {
		// We are given a talkpage article and title. Find the associated
		// non-talk article and pass that to the view.
		$article = new Article($title->getSubjectPage());
		
		if( $title->getSubjectPage()->getNamespace() == NS_LQT_THREAD ) {
			// Threads don't have talk pages; redirect to the thread page.
			$output->redirect($title->getSubjectPage()->getFullUrl());
		}

		/* Certain actions apply to the "header", which is stored in the actual talkpage
		   in the database. Drop everything and behave like a normal page if those
		   actions come up, to avoid hacking the various history, editing, etc. code. */
		$action =  $request->getVal('action');
		$header_actions = array('history', 'edit', 'submit');
		if ($request->getVal('lqt_method', null) === null &&
				( in_array( $action, $header_actions ) ||
					$request->getVal('diff', null) !== null ) ) {
			$viewname = self::$views['TalkpageHeaderView'];
		} else if ( $action == 'protect' || $action == 'unprotect' ) {
			$viewname = self::$views['ThreadProtectionFormView'];
		} else if ( $request->getVal('lqt_method') == 'talkpage_archive' ) {
			$viewname = self::$views['TalkpageArchiveView'];
		} else {
			$viewname = self::$views['TalkpageView'];
		}
		$view = new $viewname( $output, $article, $title, $user, $request );
		return $view->show();
	}

	static function threadPermalinkMain(&$output, &$article, &$title, &$user, &$request) {
		
		$action =  $request->getVal('action');
		$lqt_method = $request->getVal('lqt_method');
		
		if( $lqt_method == 'thread_history' ) {
			$viewname = self::$views['ThreadHistoryListingView'];
		}
		else if ( $lqt_method == 'diff' ) { // this clause and the next must be in this order.
			$viewname = self::$views['ThreadDiffView'];
		}
		else if ( $action == 'history'
			|| $request->getVal('diff', null) !== null
			|| $request->getVal('oldid', null) !== null ) {
			$viewname = self::$views['IndividualThreadHistoryView'];
		}
		else if ( $action == 'protect' || $action == 'unprotect' ) {
			$viewname = self::$views['ThreadProtectionFormView'];
		}
		else if ( $request->getVal('lqt_oldid', null) !== null ) {
			$viewname = self::$views['ThreadHistoricalRevisionView'];
		}
		else {
			$viewname = self::$views['ThreadPermalinkView'];
		}
		$view = new $viewname( $output, $article, $title, $user, $request );
		return $view->show();
	}
	
	/**
	* If the page we recieve is a Liquid Threads page of any kind, process it
	* as needed and return True. If it's a normal, non-liquid page, return false.
	*/
	static function tryPage( $output, $article, $title, $user, $request ) {
		if ( $title->isTalkPage() ) {
			return self::talkpageMain ($output, $article, $title, $user, $request);
		} else if ( $title->getNamespace() == NS_LQT_THREAD ) {
			return self::threadPermalinkMain($output, $article, $title, $user, $request);
		}
		return true;
	}
	
	static function onPageMove( $movepage, $ot, $nt ) {
		// We are being invoked on the subject page, not the talk page.
		
		$threads = Threads::where( array( Threads::articleClause(new Article($ot)),
		                                  Threads::topLevelClause() ));
		
		foreach ($threads as $t) {
			$t->moveToSubjectPage( $nt, false );
		}
		
		return true;
	}
	
	static function makeLinkObj( &$returnValue, &$linker, $nt, $text, $query, $trail, $prefix ) {
		if( ! $nt->isTalkPage() )
			return true;
		
		// Talkpages with headers.
		if( $nt->getArticleID() != 0 )
			return true;
			
		// Talkpages without headers -- check existance of threads.
		$article = new Article($nt->getSubjectPage());
		$threads = Threads::where(Threads::articleClause($article), "LIMIT 1");
		if( count($threads) == 0 ) {
			// We want it to look like a broken link, but not have action=edit, since that
			// will edit the header, so we can't use makeBrokenLinkObj. This code is copied
			// from the body of that method.
			$url = $nt->escapeLocalURL( $query );
			if ( '' == $text )
				$text = htmlspecialchars( $nt->getPrefixedText() );
			$style = $linker->getInternalLinkAttributesObj( $nt, $text, "yes" );
			list( $inside, $trail ) = Linker::splitTrail( $trail );
			$returnValue = "<a href=\"{$url}\"{$style}>{$prefix}{$text}{$inside}</a>{$trail}";			
		}
		else {
			$returnValue = $linker->makeKnownLinkObj( $nt, $text, $query, $trail, $prefix );
		}
		return false;
	}
	
	// One major place that doesn't use makeLinkObj is the tabs. So override known/unknown there too.
	static function tabAction(&$skintemplate, $title, $message, $selected, $checkEdit,
			&$classes, &$query, &$text, &$result) {
		if( ! $title->isTalkPage() )
			return false;
		if( $title->getArticleID() != 0 ) {
			$query = "";
			return false;
		}		
		// It's a talkpage without a header. Get rid of action=edit always,
		// color as apropriate.
		$query = "";
		$article = new Article($title->getSubjectPage());
		$threads = Threads::where(Threads::articleClause($article), "LIMIT 1");
		if( count($threads) != 0 ) {
			$i = array_search('new', $classes); if( $i !== false ) {
				array_splice($classes, $i, 1);
			}
		}
		return false;
	}
	
	static function changesListArticleLink(&$changeslist, &$articlelink, &$s, &$rc, $unpatrolled, $watched) {
		if( $rc->getTitle()->getNamespace() == NS_LQT_THREAD ) {
			$thread = Threads::withRoot(new Post( $rc->getTitle() ));
			if($thread) {
				$articlelink .= ' from ' . $changeslist->skin->makeKnownLinkObj(
					$thread->article()->getTitle()->getTalkPage() );
			}
		}
		return true;
	}
	
	static function setNewtalkHTML($skintemplate, $tpl) {
		global $wgUser, $wgTitle, $wgOut;
		
		$newmsg_t = SpecialPage::getPage('Newmessages')->getTitle();
		$watchlist_t = SpecialPage::getPage('Watchlist')->getTitle();
		$usertalk_t = $wgUser->getTalkPage();
		if( $wgUser->getNewtalk()
				&&! $newmsg_t->equals($wgTitle)
				&&! $watchlist_t->equals($wgTitle)
				&&! $usertalk_t->equals($wgTitle)
				) {
			$tpl->set("newtalk", "You have <a href=\"{$newmsg_t->getFullURL()}\">new messages</a>.");
			$wgOut->setSquidMaxage(0);
		} else {
			$tpl->set("newtalk", '');
		}

		return true;
	}
}

 
class LqtView {
	protected $article;
	protected $output;
	protected $user;
	protected $title;
	protected $request;
	
	protected $headerLevel = 2; 	/* h1, h2, h3, etc. */
	protected $maxIndentationLevel = 4;
	
	protected $user_colors;
	protected $user_color_index;
	const number_of_user_colors = 6;

	protected $queries;
	
	public $archive_start_days = 14;
	public $archive_recent_days = 5;
	
	function __construct(&$output, &$article, &$title, &$user, &$request) {
		$this->article = $article;
		$this->output = $output;
		$this->user = $user;
		$this->title = $title;
		$this->request = $request;
		$this->user_colors = array();
		$this->user_color_index = 1;
		$this->queries = $this->initializeQueries();
	}
	
	function setHeaderLevel($int) {
		$this->headerLevel = $int;
	}
	
	function initializeQueries() {
		$g = new QueryGroup();
		$startdate = Date::now()->nDaysAgo($this->archive_start_days)->midnight();
		$recentstartdate = $startdate->nDaysAgo($this->archive_recent_days);
		$article_clause = Threads::articleClause($this->article);
		$g->addQuery('fresh',
		              array($article_clause,
							'thread.thread_parent is null',
		                    '(thread.thread_modified >= ' . $startdate->text() .
		 					'  OR (thread.thread_summary_page is NULL' . 
								 ' AND thread.thread_type='.Threads::TYPE_NORMAL.'))'),
		              array('ORDER BY thread.thread_modified DESC'));
		$g->addQuery('archived',
		             array($article_clause,
							'thread.thread_parent is null',
		                   '(thread.thread_summary_page is not null' .
			                  ' OR thread.thread_type='.Threads::TYPE_NORMAL.')',
		                   'thread.thread_modified < ' . $startdate->text()),
		             array('ORDER BY thread.thread_modified DESC'));
		$g->extendQuery('archived', 'recently-archived',
		                array('( thread.thread_modified >=' . $recentstartdate->text() .
				      '  OR  rev_timestamp >= ' . $recentstartdate->text() . ')',
				      'summary_page.page_id = thread.thread_summary_page', 'summary_page.page_latest = rev_id'),
				array(),
				array('page summary_page', 'revision'));
		return $g;
	}

	static protected $occupied_titles = array();
	
	/*************************
     * (1) linking to liquidthreads pages and
     * (2) figuring out what page you're on and what you need to do.
	*************************/
	
	static function queryStringFromArray( $vars ) {
		$q = '';
		if ( $vars && count( $vars ) != 0 ) {
			foreach( $vars as $name => $value )
				$q .= "$name=$value&";
		}
		return $q;
	}

	function methodAppliesToThread( $method, $thread ) {
		return $this->request->getVal('lqt_method') == $method &&
			$this->request->getVal('lqt_operand') == $thread->id();
	}
	function methodApplies( $method ) {
		return $this->request->getVal('lqt_method') == $method;
	}

	static function permalinkUrl( $thread, $method = null, $operand = null ) {
		$query = $method ? "lqt_method=$method" : "";
		$query = $operand ? "$query&lqt_operand={$operand->id()}" : $query;
		return $thread->root()->getTitle()->getFullUrl($query);
	}

	/* This is used for action=history so that the history tab works, which is
	   why we break the lqt_method paradigm. */
	static function permalinkUrlWithQuery( $thread, $query ) {
		if ( is_array($query) ) $query = self::queryStringFromArray($query);
		return $thread->root()->getTitle()->getFullUrl($query);
	}
	
	static function permalinkUrlWithDiff( $thread ) {
		$changed_thread = $thread->changeObject();
		$curr_rev_id = $changed_thread->rootRevision();
		$curr_rev = Revision::newFromTitle( $changed_thread->root()->getTitle(), $curr_rev_id );
		$prev_rev = $curr_rev->getPrevious();
		$oldid = $prev_rev ? $prev_rev->getId() : "";
		return self::permalinkUrlWithQuery( $changed_thread, array('lqt_method'=>'diff', 'diff'=>$curr_rev_id, 'oldid'=>$oldid) );
	}

	static function talkpageUrl( $title, $method = null, $operand = null, $includeFragment = true ) {
		$query = $method ? "lqt_method=$method" : "";
		$query = $operand ? "$query&lqt_operand={$operand->id()}" : $query;
		return $title->getFullURL( $query ) . ($operand && $includeFragment ? "#lqt_thread_{$operand->id()}" : "");
	}
	
	
	/**
     * Return a URL for the current page, including Title and query vars,
	 * with the given replacements made.
     * @param $repls array( 'name'=>new_value, ... )
	*/
	function queryReplace( $repls ) {
		$vs = $this->request->getValues();
		$rs = array();
/*		foreach ($vs as $k => $v) {
			if ( array_key_exists( $k, $repls ) ) {
				$rs[$k] = $repls[$k];
			} else {
				$rs[$k] = $vs[$k];
			}
		}
*/
		foreach ( $repls as $k => $v ) {
			$vs[$k] = $v;
		}
		return $this->title->getFullURL(self::queryStringFromArray($vs));
	}

	/*************************************************************
	* Editing methods (here be dragons)                          *
    * Forget dragons: This section distorts the rest of the code *
    * like a star bending spacetime around itself.               *
	*************************************************************/

	/**
	 * Return an HTML form element whose value is gotten from the request.
	 * TODO: figure out a clean way to expand this to other forms.
	 */
	function perpetuate( $name, $as ) {
		$value = $this->request->getVal($name, '');
		if ( $as == 'hidden' ) {
			return <<<HTML
			<input type="hidden" name="$name" id="$name" value="$value">
HTML;
		}
	}

	function showReplyProtectedNotice($thread) {
		$log_url = SpecialPage::getPage('Log')->getTitle()->getFullURL(
			"type=protect&user=&page={$thread->title()->getPrefixedURL()}");
		$this->output->addHTML("<p>This thread has been <a href=\"$log_url\">protected</a> from being replied to.");
	}

	function showNewThreadForm() {
		$this->showEditingFormInGeneral( null, 'new', null );
	}

	function showPostEditingForm( $thread ) {
		$this->showEditingFormInGeneral( $thread, 'editExisting', null );
	}

	function showReplyForm( $thread ) {
		if( $thread->root()->getTitle()->userCan( 'edit' ) ) {
			$this->showEditingFormInGeneral( null, 'reply', $thread );
		} else {
			$this->showReplyProtectedNotice($thread);
		}
	}

	function showSummarizeForm( $thread ) {
		$this->showEditingFormInGeneral( null, 'summarize', $thread );
	}

	private function showEditingFormInGeneral( $thread, $edit_type, $edit_applies_to ) {		
		/*
		 EditPage needs an Article. If there isn't a real one, as for new posts,
		 replies, and new summaries, we need to generate a title. Auto-generated
		 titles are based on the subject line. If the subject line is blank, we
		 can temporarily use a random scratch title. It's fine if the title changes
		 throughout the edit cycle, since the article doesn't exist yet anyways.
		*/
		if ($edit_type == 'summarize' && $edit_applies_to->summary() ) {
			$article = $edit_applies_to->summary();
		} else if ($edit_type == 'summarize') {
			$t = $this->newSummaryTitle($edit_applies_to);
			$article = new Article($t);
		} else if ( $thread == null ) {
			$subject = $this->request->getVal('lqt_subject_field', '');
			if ($edit_type == 'new') {
				$t = $this->newScratchTitle($subject);
			} else if ($edit_type == 'reply') {
				$t = $this->newReplyTitle($subject, $edit_applies_to);
			}
			$article = new Article($t);
		} else {
			$article = $thread->root();
		}
		
		$e = new EditPage($article);
		
		$e->suppressIntro = true;
		$e->editFormTextBeforeContent .=
			$this->perpetuate('lqt_method', 'hidden') .
			$this->perpetuate('lqt_operand', 'hidden');
		
		if ( $edit_type=='new' || ($thread && !$thread->hasSuperthread()) ) {
			// This is a top-level post; show the subject line.
			$sbjtxt = $thread ? $thread->subjectWithoutIncrement() : '';
			$subject = $this->request->getVal('lqt_subject_field', $sbjtxt);
			$e->editFormTextBeforeContent .= <<<HTML
			<label for="lqt_subject_field">Subject: </label>
			<input type="text" size="60" name="lqt_subject_field" id="lqt_subject_field" value="$subject" tabindex="1"><br>
HTML;
		}

		$e->edit();

		// Override what happens in EditPage::showEditForm, called from $e->edit():
//		$wgOut->setArticleRelated( false ); 
		$this->output->setArticleFlag( false );

		// For replies and new posts, insert the associated thread object into the DB.
		if ($edit_type != 'editExisting' && $edit_type != 'summarize' && $e->didSave) {
			if ( $edit_type == 'reply' ) {
				$thread = Threads::newThread( $article, $this->article, $edit_applies_to, $e->summary );
				$edit_applies_to->commitRevision(Threads::CHANGE_REPLY_CREATED, $thread, $e->summary);
			} else {
				$thread = Threads::newThread( $article, $this->article, null, $e->summary );
			}
		}
		
		if ($edit_type == 'summarize' && $e->didSave) {
			$edit_applies_to->setSummary( $article );
			$edit_applies_to->commitRevision(Threads::CHANGE_EDITED_SUMMARY, $edit_applies_to, $e->summary);
		}
		
		// Move the thread and replies if subject changed.
		if( $edit_type == 'editExisting' && $e->didSave ) {
			$subject = $this->request->getVal('lqt_subject_field', '');
			if ( $subject && $subject != $thread->subjectWithoutIncrement() ) {
//				$reason = $this->request->getVal("wpSummary", "");
				$this->renameThread($thread, $subject, $e->summary);
			}
			// this is unrelated to the subject change and is for all edits:
			$thread->setRootRevision( Revision::newFromTitle($thread->root()->getTitle()) );
			$thread->commitRevision( Threads::CHANGE_EDITED_ROOT, $thread, $e->summary );
		}
				
		// A redirect without $e->didSave will happen if the new text is blank (EditPage::attemptSave).
		// This results in a new Thread object not being created for replies and new discussions,
		// so $thread is null. In that case, just allow editpage to redirect back to the talk page.
		if ( $this->output->getRedirect() != '' && $thread ) {
			$this->output->redirect( $this->title->getFullURL() . '#' . 'lqt_thread_' . $thread->id() );
		}
	}
	
	function renameThread($t,$s,$reason) {
		$this->simplePageMove($t->root()->getTitle(),$s, $reason);
		// TODO here create a redirect from old page to new.
		foreach( $t->subthreads() as $st ) {
			$this->renameThread($st, $s, $reason);
		}
	}
	
	function scratchTitle() {
		$token = md5(uniqid(rand(), true));
		return Title::newFromText( "Thread:$token" );
	}
	function newScratchTitle($subject) {
		return $this->incrementedTitle( $subject?$subject:"«no subject»", NS_LQT_THREAD );
	}
	function newSummaryTitle($t) {
		return $this->incrementedTitle( $t->subject(), NS_LQT_SUMMARY );
	}
	function newReplyTitle($s, $t) {
		return $this->incrementedTitle( $t->subjectWithoutIncrement(), NS_LQT_THREAD );
	}
	/** Keep trying titles starting with $basename until one is unoccupied. */
	function incrementedTitle($basename, $namespace) {
		$i = 1; do {
			$t = Title::newFromText( $basename.'_('.$i.')', $namespace );
			$i++;
		} while ( $t->exists() || in_array($t->getPrefixedDBkey(), self::$occupied_titles) );
		return $t;
	}

	/* Adapted from MovePageForm::doSubmit in SpecialMovepage.php. */
	function simplePageMove( $old_title, $new_subject, $reason ) {
		if ( $this->user->pingLimiter( 'move' ) ) {
			$this->out->rateLimited();
			return false;
		}

		# Variables beginning with 'o' for old article 'n' for new article

		$ot = $old_title;
		$nt = $this->incrementedTitle($new_subject, $old_title->getNamespace());

		self::$occupied_titles[] = $nt->getPrefixedDBkey();

		# don't allow moving to pages with # in
		if ( !$nt || $nt->getFragment() != '' ) {
			echo "malformed title"; // TODO real error reporting.
			return false;
		}

		$error = $ot->moveTo( $nt, true, "Changed thread subject: $reason" );
		if ( $error !== true ) {
			var_dump($error);
			echo "something bad happened trying to rename the thread."; // TODO
			return false;
		}

		# Move the talk page if relevant, if it exists, and if we've been told to
		 // TODO we need to implement correct moving of talk pages everywhere later.
		// Snipped.

		return true;
	}

	/**
	* Example return value:
	*   array (
	*       0 => array( 'label'   => 'Edit',
	*                   'href'    => 'http...',
	*                   'enabled' => false ),
	*       1 => array( 'label'   => 'Reply',
	*                   'href'    => 'http...',
	*                   'enabled' => true )
	*   )
	*/
	function applicableThreadCommands($thread) {
		$commands = array();
		
		$user_can_edit = $thread->root()->getTitle()->quickUserCan( 'edit' );

		$commands[] = array( 'label' => $user_can_edit ? wfMsg('edit') : wfMsg('viewsource'),
		                     'href' => $this->talkpageUrl( $this->title, 'edit', $thread ),
		                     'enabled' => true );

		$commands[] = array( 'label' => wfMsg('history_short'),
							 'href' =>  $this->permalinkUrlWithQuery($thread, 'action=history'),
							 'enabled' => true );
		
		$commands[] = array( 'label' => wfMsg('lqt_permalink'),
							 'href' =>  $this->permalinkUrl( $thread ),
							 'enabled' => true );

		if ( in_array('delete',  $this->user->getRights()) ) {
			$delete_url = SpecialPage::getPage('Deletethread')->getTitle()->getFullURL()
				. '/' . $thread->title()->getPrefixedURL();
			$commands[] = array( 'label' => $thread->type() == Threads::TYPE_DELETED ? wfMsg('lqt_undelete') : wfMsg('delete'),
								 'href' =>  $delete_url,
								 'enabled' => true );
		}
							
		$commands[] = array( 'label' => '<b class="lqt_reply_link">' . wfMsg('lqt_reply') . '</b>',
							 'href' =>  $this->talkpageUrl( $this->title, 'reply', $thread ),
							 'enabled' => $user_can_edit );

		return $commands;
	}

	/*************************
	* Output methods         *
	*************************/
	
	static function addJSandCSS() {
		// Changed this to be static so that we can call it from
		// wfLqtBeforeWatchlistHook.
		global $wgJsMimeType, $wgScriptPath, $wgStyleVersion; // TODO globals.
		global $wgOut;
		$s = <<< HTML
		<script type="{$wgJsMimeType}" src="{$wgScriptPath}/extensions/LiquidThreads/lqt.js"><!-- lqt js --></script>
		<style type="text/css" media="screen, projection">/*<![CDATA[*/
			@import "{$wgScriptPath}/extensions/LiquidThreads/lqt.css?{$wgStyleVersion}";
		/*]]>*/</style>

HTML;
		$wgOut->addScript($s);
	}

	/* @return False if the article and revision do not exist and we didn't show it, true if we did. */
	function showPostBody( $post, $oldid = null ) {
		/* Why isn't this all encapsulated in Article somewhere? TODO */
		global $wgEnableParserCache;

		// Should the parser cache be used?
		$pcache = $wgEnableParserCache &&
		          intval( $this->user->getOption( 'stubthreshold' ) ) == 0 &&
		          $post->exists() &&
				  $oldid === null;
		wfDebug( 'LqtView::showPostBody using parser cache: ' . ($pcache ? 'yes' : 'no' ) . "\n" );
		if ( $this->user->getOption( 'stubthreshold' ) ) {
			wfIncrStats( 'pcache_miss_stub' );
		}

		$outputDone = false;
		if ( $pcache ) {
			$outputDone = $this->output->tryParserCache( $post, $this->user );
		}

		if (!$outputDone) {
			// Cache miss; parse and output it.
			$rev = Revision::newFromTitle( $post->getTitle(), $oldid );
			if ($rev) {
				$this->output->addPrimaryWikiText( $rev->getText(), $post, true );
			//	$this->output->addWikiText( $rev->getText() );
				return true;
			} else {
				return false;
			}
		} else {
			return true;
		}
	}

	function showThreadFooter( $thread ) {
		global $wgLang; // TODO global.
		
		$author = $thread->root()->originalAuthor();
		$color_number = $this->selectNewUserColor($author);

		$sig = $this->user->getSkin()->userLink( $author->getID(), $author->getName() ) .
			   $this->user->getSkin()->userToolLinks( $author->getID(), $author->getName() );

		$timestamp = $wgLang->timeanddate($thread->modified());
		
		$this->output->addHTML(<<<HTML
<ul class="lqt_footer">
<li class="lqt_author_sig lqt_post_color_{$color_number}">$sig</li>
<li>$timestamp</li>
HTML
		);
		
		foreach( $this->applicableThreadCommands($thread) as $command ) {
			$label = $command['label'];
			$href = $command['href'];
			$enabled = $command['enabled'];
			
			if( $enabled ) {
				$this->output->addHTML( "<li><a href=\"$href\">$label</a></li>" );
			} else {
				$this->output->addHTML( "<li><span class=\"lqt_footer_disabled\">$label</span></li>" );
			}
		}
		
		$this->output->addHTML('</ul>');
	}

	function selectNewUserColor( $user ) {
		$userkey = $user->isAnon() ? "anon:" . $user->getName() : "user:" . $user->getId();
		
		if( !array_key_exists( $userkey, $this->user_colors ) ) {
			$this->user_colors[$userkey] = $this->user_color_index;
			$this->user_color_index += 1;
			if ( $this->user_color_index > self::number_of_user_colors ) {
				$this->user_color_index = 1;
			}
		}
		return $this->user_colors[$userkey];
	}

	function showRootPost( $thread ) {
		$popts = $this->output->parserOptions();
		$previous_editsection = $popts->getEditSection();
		$popts->setEditSection(false);
		$this->output->parserOptions($popts);
		
		$post = $thread->root();

		$oldid = $thread->isHistorical() ? $thread->rootRevision() : null;
		
		$this->openDiv( $this->postDivClass($thread) );
		
		if( $this->methodAppliesToThread( 'edit', $thread ) ) {
			$this->showPostEditingForm( $thread );
		} else{
			$this->showPostBody( $post, $oldid );
			$this->showThreadFooter( $thread );
		}
		
		$this->closeDiv();
		
		if( $this->methodAppliesToThread( 'reply', $thread ) ) {
			$this->indent();
			$this->showReplyForm( $thread );
			$this->unindent();
		}
		
		$popts->setEditSection($previous_editsection);
		$this->output->parserOptions($popts);
	}

	function showThreadHeading( $thread ) {
		if ( $thread->hasDistinctSubject() ) {
			$html = $thread->subjectWithoutIncrement() .
			        ' <span class="lqt_subject_increment">(' .
			        $thread->increment() . ')</span>';
			$this->output->addHTML( "<h{$this->headerLevel} class=\"lqt_header\"><span class=\"mw-headline\">"
				. $html . "</span></h{$this->headerLevel}>" );
		}
	}
	
	function postDivClass( $thread ) {
		return 'lqt_post';
	}
	
	function anchorName($thread) {
		return "lqt_thread_{$thread->id()}";
	}

	function showThread( $thread ) {
		global $wgLang; # TODO global.
		
		$this->showThreadHeading( $thread );
		
		$this->output->addHTML( "<a name=\"{$this->anchorName($thread)}\" ></a>" );

		if ($thread->type() == Threads::TYPE_MOVED) {
			$revision = Revision::newFromTitle( $thread->title() );
			$target = Title::newFromRedirect( $revision->getText() );
			$t_thread = Threads::withRoot( new Article( $target ) );
			$p = new Parser(); $sig = $p->getUserSig( $thread->root()->originalAuthor() );

			$this->output->addHTML( "This thread is a placeholder indicating that a thread, <a href=\"{$target->getFullURL()}\">{$target->getText()}</a>, was removed from this page to another talk page. This move was made by " );
			$this->output->addWikitext( $sig, false );
			$this->output->addHTML( " at " );
			$this->output->addHTML( $wgLang->timeanddate($thread->modified()) );
			$this->output->addHTML( "." );
			
			return;
		}

		
		if ( $thread->type() == Threads::TYPE_DELETED ) {
			if ( in_array('deletedhistory',  $this->user->getRights()) ) {
				$this->output->addHTML("<p>The following thread has been <b>deleted</b> and is invisible to non-sysops.</p>");
			}
			else {
				$this->output->addHTML("<p><em>This thread was deleted.</em></p>");
				return;
			}
		}

		$timestamp = new Date($thread->modified());
		if( $thread->summary() ) {
			$this->showSummary($thread);
		} else if ( $timestamp->isBefore(Date::now()->nDaysAgo($this->archive_start_days))
		            && !$thread->summary() && !$thread->hasSuperthread() && !$thread->isHistorical() ) {
			$this->output->addHTML("<p class=\"lqt_summary_notice\">If this discussion seems to be concluded, you are encouraged to <a href=\"{$this->permalinkUrl($thread, 'summarize')}\">write a summary</a>. There have been no changes here for at least $this->archive_start_days days.</p>");
		}

		$this->openDiv('lqt_thread', "lqt_thread_id_{$thread->id()}");
		
		$this->showRootPost( $thread );
		$this->indent();
		foreach( $thread->subthreads() as $st ) {
			$this->showThread($st);
		}
		$this->unindent();
		
		$this->closeDiv();
	}

	function indent() {
		if( $this->headerLevel <= $this->maxIndentationLevel ) {
			$this->output->addHTML('<dl class="lqt_replies"><dd>');
		} else {
			$this->output->addHTML('<div class="lqt_replies_without_indent">');
		}
		$this->headerLevel += 1;
	}
	function unindent() {
		if( $this->headerLevel <= $this->maxIndentationLevel + 1 ) {
			$this->output->addHTML('</dd></dl>');
		} else {
			$this->output->addHTML('</div>');
		}
		$this->headerLevel -= 1;
	}

	function openDiv( $class='', $id='' ) {
		$this->output->addHTML( wfOpenElement( 'div', array('class'=>$class, 'id'=>$id) ) );
	}

	function closeDiv() {
		$this->output->addHTML( wfCloseElement( 'div' ) );
	}
	
	function showSummary($t) {
		if ( !$t->summary() ) return;
		$this->output->addHTML(<<<HTML
			<div class='lqt_thread_permalink_summary'>
			<span class="lqt_thread_permalink_summary_title">
			This thread has been summarized as follows:
			</span><span class="lqt_thread_permalink_summary_edit">
			[<a href="{$this->permalinkUrl($t,'summarize')}">edit</a>]
			</span>
HTML
		);
		$this->openDiv('lqt_thread_permalink_summary_body');
		$this->showPostBody($t->summary());
		$this->closeDiv();
		$this->closeDiv();
	}

}

?>

<?php

class LqtDeletionController {
	static $pageids_to_revive;
	
	static function onArticleDeleteComplete( &$article, &$user, $reason, $id ) {
		$title = $article->getTitle();
		
		if ($title->getNamespace() != NS_LQT_THREAD) {
			return true;
		}
		
		$threads = Threads::where( array( 'thread_root' => $id ) );
		
		if (!count($threads)) {
			wfDebugLog( __METHOD__.": no threads with root $id, ignoring...\n" );
			return true;
		}
		
		$thread = array_pop($threads);
		
		// Mark the thread as deleted
		$thread->delete($reason);
		
		// Avoid orphaning subthreads, update their parentage.
		wfLoadExtensionMessages( 'LiquidThreads' );
		if ( $thread->replies() && $thread->isTopmostThread() ) {
			$reason = wfMsg('lqt-delete-parent-deleted', $reason );
			foreach( $thread->replies() as $reply ) {
				$reply->root()->doDeleteArticle( $reason, false, $reply->root()->getId() );
			}
			global $wgOut;
			$wgOut->addWikiMsg( 'lqt-delete-replies-done' );
		} elseif ( $thread->replies() ) {
			foreach( $thread->replies() as $reply ) {
				$reply->setSuperthread( $thread->superthread() );
				$reply->save( );
			}
		}
		
		return true;
	}
	
	static function onArticleRevisionUndeleted( &$title, $revision, $page_id ) {
		if ( $title->getNamespace() == NS_LQT_THREAD ) {
			self::$pageids_to_revive[$page_id] = $title;
		}
		
		return true;
	}
	
	static function onArticleUndelete( &$udTitle, $created, $comment = '' ) {
		if ( empty(self::$pageids_to_revive) ) {
			return true;
		}
		
		foreach( self::$pageids_to_revive as $pageid => $title ) {
			if ($pageid == 0) {
				continue;
			}
			
			// Try to get comment for old versions where it isn't passed, hacky :(
			if (!$comment) {
				global $wgRequest;
				$comment = $wgRequest->getText( 'wpComment' );
			}
			
			// TX has not been committed yet, so we must select from the master
			$dbw = wfGetDB( DB_MASTER );
			$res = $dbw->select( 'thread', '*', array( 'thread_root' => $pageid ), __METHOD__ );
			$threads = Threads::loadFromResult( $res, $dbw );
			
			if ( count($threads) ) {
				$thread = array_pop($threads);
				$thread->setRoot( new Article( $title ) );
				$thread->undelete( $comment );
			} else {
				wfDebug( __METHOD__. ":No thread found with root set to $pageid (??)\n" );
			}
		}
		
		return true;
	}
	
	static function onArticleConfirmDelete( $article, $out, &$reason ) {
		if ($article->getTitle()->getNamespace() != NS_LQT_THREAD) return true;
		
		$thread = Threads::withRoot( $article );
		
		if ( $thread->isTopmostThread() && count($thread->replies()) ) {
			wfLoadExtensionMessages( 'LiquidThreads' );
			$out->wrapWikiMsg( '<strong>$1</strong>',
								'lqt-delete-parent-warning' );
		}
		
		return true;
	}
}

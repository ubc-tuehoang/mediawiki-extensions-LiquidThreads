<?php

if ( !defined( 'MEDIAWIKI' ) ) die;

class NewUserMessagesView extends LqtView {

	protected $threads;
	protected $tops;
	protected $targets;

	protected function htmlForReadButton( $label, $title, $class, $ids ) {
		$ids_s = implode( ',', $ids );
		$html = '';
		$html .= Xml::hidden( 'lqt_method', 'mark_as_read' );
		$html .= Xml::hidden( 'lqt_operand', $ids_s );
		$html .= Xml::submitButton( $label, array( 'name' => 'lqt_read_button',
									'title' => $title ) );
		$html = Xml::tags( 'form', array( 'method' => 'post', 'class' => $class ), $html );
		
		return $html;
	}

	function getReadAllButton( $threads ) {
		wfLoadExtensionMessages( 'LiquidThreads' );
		$ids =  array_map( create_function( '$t', 'return $t->id();' ), $threads ); // ew
		return $this->htmlForReadButton(
					wfMsg( 'lqt-read-all' ),
					wfMsg( 'lqt-read-all-tooltip' ),
					"lqt_newmessages_read_all_button",
					$ids
				);
	}

	function getUndoButton( $ids ) {
		wfLoadExtensionMessages( 'LiquidThreads' );
		
		if ( count( $ids ) == 1 ) {
			$t = Threads::withId( $ids[0] );
			if ( !$t )
				return; // empty or just bogus operand.
			$msg = wfMsgExt( 'lqt-marked-read', 'parseinline', array($t->subject())  );
		} else {
			$count = count( $ids );
			$msg =  wfMsgExt( 'lqt-count-marked-read', 'parseinline', array($count) );
		}
		$operand = implode( ',', $ids );
		
		$html = '';
		$html .= $msg;
		$html .= Xml::hidden( 'lqt_method', 'mark_as_unread' );
		$html .= Xml::hidden( 'lqt_operand', $operand );
		$html .= Xml::submitButton( wfMsg('lqt-email-undo'), array( 'name' => 'lqt_read_button',
					'title' => wfMsg( 'lqt-email-info-undo' ) ) );
					
		$html = Xml::tags( 'form',
							array( 'method' => 'post', 'class' => 'lqt_undo_mark_as_read' ),
							$html );
		
		return $html;
	}

	function postDivClass( $thread ) {
		$topid = $thread->topmostThread()->id();
		if ( in_array( $thread->id(), $this->targets[$topid] ) )
			return 'lqt_post_new_message';
		else
			return 'lqt_post';
	}

	function showOnce() {
		self::addJSandCSS();

		if ( $this->request->wasPosted() ) {
			// If they just viewed this page, maybe they still want that notice.
			// But if they took the time to dismiss even one message, they
			// probably don't anymore.
			$this->user->setNewtalk( false );
		}

		if ( $this->request->wasPosted() && $this->methodApplies( 'mark_as_unread' ) ) {
			$ids = explode( ',', $this->request->getVal( 'lqt_operand', '' ) );
			
			if ( $ids !== false ) {
				foreach ( $ids as $id ) {
					$tmp_thread = Threads::withId( $id );	if ( $tmp_thread )
						NewMessages::markThreadAsUnReadByUser( $tmp_thread, $this->user );
				}
				$this->output->redirect( $this->title->getFullURL() );
			}
		} elseif ( $this->request->wasPosted() && $this->methodApplies( 'mark_as_read' ) ) {
			$ids = explode( ',', $this->request->getVal( 'lqt_operand' ) );
			if ( $ids !== false ) {
				foreach ( $ids as $id ) {
					$tmp_thread = Threads::withId( $id );
					if ( $tmp_thread )
						NewMessages::markThreadAsReadByUser( $tmp_thread, $this->user );
				}
				$query = 'lqt_method=undo_mark_as_read&lqt_operand=' . implode( ',', $ids );
				$this->output->redirect( $this->title->getFullURL( $query ) );
			}
		} elseif ( $this->methodApplies( 'undo_mark_as_read' ) ) {
			$ids = explode( ',', $this->request->getVal( 'lqt_operand', '' ) );
			$this->output->addHTML( $this->getUndoButton( $ids ) );
		}
	}

	function show() {
		if ( ! is_array( $this->threads ) ) {
			throw new MWException( 'You must use NewUserMessagesView::setThreads() before calling NewUserMessagesView::show().' );
		}

		// Do everything by id, because we can't depend on reference identity; a simple Thread::withId
		// can change the cached value and screw up your references.

		$this->targets = array();
		$this->tops = array();
		foreach ( $this->threads as $t ) {
			$top = $t->topmostThread();
			
			// It seems that in some cases $top is zero.
			if (!$top)
				continue;

			if ( !array_key_exists( $top->id(), $this->tops ) )
				$this->tops[$top->id()] = $top;
			if ( !array_key_exists( $top->id(), $this->targets ) )
				$this->targets[$top->id()] = array();
			$this->targets[$top->id()][] = $t->id();
		}

		foreach ( $this->tops as $t ) {
			// It turns out that with lqtviews composed of threads from various talkpages,
			// each thread is going to have a different article... this is pretty ugly.
			$this->article = $t->article();

			$this->showWrappedThread( $t );
		}
		return false;
	}
	
	function showWrappedThread( $t ) {
		wfLoadExtensionMessages( 'LiquidThreads' );
		
		$read_button = $this->htmlForReadButton(
			wfMsg( 'lqt-read-message' ),
			wfMsg( 'lqt-read-message-tooltip' ),
			'lqt_newmessages_read_button',
			$this->targets[$t->id()] );
		
		// Left-hand column � read button and context link to the full thread.
		$topmostThread = $t->topmostThread();
		$contextLink = $this->permalink( $topmostThread,
						wfMsgExt( 'lqt-newmessages-context', 'parseinline' ) );
		$leftColumn = Xml::tags( 'p', null, $read_button ) .
						Xml::tags( 'p', null, $contextLink );
		$leftColumn = Xml::tags( 'td', array( 'class' => 'mw-lqt-newmessages-left' ),
									$leftColumn );
		$html = "<table><tr>$leftColumn<td>";
		$this->output->addHTML( $html );

		$this->showThread( $t );
		
		$this->output->addHTML( "</td></tr></table>" );
	}

	function setThreads( $threads ) {
		$this->threads = $threads;
	}
}

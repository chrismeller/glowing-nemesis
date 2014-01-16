<?php
	
	class Controller_API_Ventureloop extends Controller_API {
		
		public function __construct ( \Request $request ) {
			
			parent::__construct( $request );
			
			$vendor = Finder::search('vendor', 'ventureloop/ventureloop');
			
			if ( $vendor == false ) {
				throw new Exception('Unable to find VentureLoop vendor library!');
			}
			
			include( $vendor );
			
		}
		
		public function get_atom ( $criteria, $location ) {
			
			$location = urldecode( $location );
			
			// see if we have prior results cached, just so we don't hammer their servers for no reason
			$cache_key = 'api_ventureloop_' . sha1( implode( '|', array( $criteria, $location ) ) );
			try {
				$cached = Cache::get( $cache_key );

				return $this->create_response( $cached );
			}
			catch( \CacheNotFoundException $e ) {
				// nothing, we'll just go on
			}
			
			$v = VentureLoop::factory()->search( $criteria, $location );
			$jobs = $v->jobs();
			
			// create our new DOM document
			$dom = new DOMDocument( '1.0', 'utf-8' );
			$dom->formatOutput = true;
			
			// create the root feed node with its namespace
			$feed = $dom->createElementNS( 'http://www.w3.org/2005/Atom', 'feed' );
			
			// create the title node
			$title_text = implode( ' in ', array( $v->results->keywords, $v->results->location ) );
			$title = $dom->createElement( 'title' );
			$title->appendChild( $dom->createTextNode( 'VentureLoop - ' . $title_text ) );
			
			// add the title to the feed node
			$feed->appendChild( $title );
			
			// and the link node
			$link = $dom->createElement( 'link' );
			$link->setAttribute( 'href', 'http://www.ventureloop.com' );
			
			// add it to the feed node
			$feed->appendChild( $link );
			
			// add the "required" "self" link node
			$self = $dom->createElement( 'link' );
			$self->setAttribute( 'href', Uri::current() );
			$self->setAttribute( 'rel', 'self' );
			
			// add it to the feed node
			$feed->appendChild( $self );
			
			// figure out the last updated date - should be the posted date of the first job, if we have one
			if ( count( $jobs ) > 0 ) {
				$last_updated = $jobs[0]->posted_on;
			}
			else {
				// otherwise, it's now - we just checked
				$last_updated = new DateTime();
			}
			
			$updated = $dom->createElement( 'updated', $last_updated->format( DateTime::ATOM ) );
			
			$feed->appendChild( $updated );
			
			$author = $dom->createElement( 'author' );
			$author_name = $dom->createElement( 'name', 'VentureLoop' );
			
			$author->appendChild( $author_name );
			
			$feed->appendChild( $author );
			
			// all of this tries to come up with a unique ID to represent this exact search... and formats it as a UUID
			$search_key = $v->results->keywords . $v->results->location . $v->results->distance . implode( ',', $v->results->categories ) . implode( ',', $v->results->search_in ) . $v->results->posted . $v->email . ( $v->results->session_id != null );
			$uuid = hash( 'md5', $search_key );		// md5 so we get 32 chars back
			
			$uuid_hex = $this->uuid_hex( $uuid );
			
			$id = $dom->createElement( 'id', 'urn:uuid:' . $uuid_hex );
			
			$feed->appendChild( $id );
			
			foreach ( $jobs as $job ) {
				
				$entry = $dom->createElement( 'entry' );

				$title = $dom->createElement( 'title' );
				$title->appendChild( $dom->createTextNode( $job->title . ' at ' . $job->company->name ) );

				$link = $dom->createElement( 'link' );
				$link->setAttribute( 'href', $job->url );

				$uuid = hash( 'md5', $job->id );
				$uuid_hex = $this->uuid_hex( $uuid );
				$id = $dom->createElement( 'id', 'urn:uuid:' . $uuid_hex );

				$updated = $dom->createElement( 'updated', $job->posted_on->format( DateTime::ATOM ) );

				$summary = $dom->createElement( 'summary' );
				$summary->appendChild( $dom->createTextNode( $job->description ) );
				$summary->setAttribute( 'type', 'html' );

				$entry->appendChild( $title );
				$entry->appendChild( $link );
				$entry->appendChild( $id );
				$entry->appendChild( $updated );
				$entry->appendChild( $summary );

				$feed->appendChild( $entry );
				
			}
			
			// add the root feed node to the document
			$dom->appendChild( $feed );
			
			// generate the xml
			$xml = $dom->saveXML();
			
			// save the cache
			Cache::set( $cache_key, $xml, 43200 );		// cache for 12 hours
			
			return $this->create_response( $xml );
			
		}
		
		private function create_response ( $content ) {
			
			$response = Response::forge( $content, 200, array( 'Content-Type', 'text/xml' ) );
			
			return $response;
			
		}
		
		private function uuid_hex ( $uuid ) {
			$uuid = str_split( $uuid );
			$uuid_hex = '';
			for ( $i = 0; $i < 32; $i++ ) {
				if ( $i == 8 || $i == 12 || $i == 16 || $i == 20 ) {
					$uuid_hex .= '-';
				}
				$uuid_hex .= $uuid[ $i ];
			}

			return $uuid_hex;
		}
		
	}
	
?>
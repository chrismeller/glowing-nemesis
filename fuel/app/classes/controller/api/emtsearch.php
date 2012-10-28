<?php

	class Controller_API_EMTSearch extends Controller_API {

		private $base_url = 'https://vo.dshs.state.tx.us/datamart/';
		private $login_url = 'https://vo.dshs.state.tx.us/datamart/txrasMainMenu.do';
		private $search_url = 'https://vo.dshs.state.tx.us/datamart/searchByNameTXRAS.do';

		public function get_TX ( ) {

			$first_name = Input::get( 'first_name' );
			$last_name = Input::get( 'last_name' );

			// if we have supplied neither, abort
			if ( $first_name == null && $last_name == null ) {
				return $this->error( 'No search criteria!' );
			}

			// first, see if we have a saved session cookie
			try {
				$session_cookie = Cache::get( 'api_emtsearch__session_cookie' );
			}
			catch ( \CacheNotFoundException $e ) {

				// if we don't have an unexpired cookie, we have to get one
				$result = file_get_contents( $this->login_url );

				foreach ( $http_response_header as $header ) {
					if ( strpos( $header, 'Set-Cookie' ) !== false ) {
						preg_match( '/Set-Cookie: ([^\b;]+);/', $header, $matches );
						$session_cookie = $matches[1];
					}
				}

				if ( !isset( $session_cookie ) ) {
					return $this->error( 'Unable to start session!' );
				}

			}

			$fields = array(
				'searchType' => 'name',
				'indOrgInd' => 'I',
				'surname' => $last_name,
				'firstName' => $first_name,
				'organizationName' => null,
				'pageSize' => 500,
				'search' => 'Search',
			);

			$options = array(
				'http' => array(
					'method' => 'POST',
					'header' => array(
						'Content-Type: application/x-www-form-urlencoded',
						'Cookie: ' . $session_cookie,
						'Referer: https://vo.dshs.state.tx.us/datamart/searchByNameTXRAS.do',
					),
					'content' => http_build_query( $fields ),
				),
			);


			$context = stream_context_create( $options );

			$result = file_get_contents( $this->search_url, false, $context );

			// first we can just check to see if there are no results
			if ( strpos( $result, 'No results matched your search criteria' ) !== false ) {
				$people = array();
			}
			else {

				$dom = new DOMDocument();
				@$dom->loadHTML( $result );

				$xpath = new DOMXpath( $dom );

				// check for an error
				$error_lis = $xpath->query( '//*[@id="errorBox"]/ul/li' );

				if ( $error_lis->length > 0 ) {

					$errors = array();
					foreach ( $error_lis as $error_li ) {
						$errors[] = $error_li->nodeValue;
					}

					return $this->error( implode( '; ', $errors ) );

				}
				else {

					// first, get the header row cells
					$ths = $xpath->query( '//*[@id="contentBox"]/form/table[2]/thead/tr/th' );

					$headers = array();
					foreach ( $ths as $th ) {
						$headers[] = $th->nodeValue;
					}

					$trs = $xpath->query( '//*[@id="contentBox"]/form/table[2]/tbody/tr' );

					$people = array();
					foreach ( $trs as $tr ) {

						// create an empty keyed array
						$person = array_combine( $headers, array_fill( 0, count( $headers ), null ) );

						// the name is the first one and requires special handling
						$p = $xpath->query( './/a', $tr );
						$name = $p->item(0)->nodeValue;
						$link = $p->item(0)->getAttribute( 'href' );

						// if there is no ://, prefix it with the domain we think it is
						if ( strpos( $link, '://' ) === false ) {
							$link = $this->base_url . $link;
						}

						$person['Name'] = trim( $name );
						$person['Link'] = $link;

						// the rest are in their own cells
						$items = $xpath->query( './td/span', $tr );
						for ( $i = 1; $i < $items->length; $i++ ) {
							$key = $headers[ $i ];
							$value = $items->item( $i )->nodeValue;

							$person[ $key ] = trim( $value );
						}

						$people[] = $person;

					}

				}

			}

			return $this->response( array( 'status' => 'ok', 'people' => $people ) );

		}

		private function error ( $message ) {

			$this->response( array( 'status' => 'error', 'message' => $message ) );

		}

	}

?>
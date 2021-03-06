<?php

namespace ShoppingFeed\ShoppingFeedWC\Feed;

use ShoppingFeed\Feed\ProductGenerator;
use ShoppingFeed\ShoppingFeedWC\Products\Products;
use ShoppingFeed\ShoppingFeedWC\ShoppingFeedHelper;

class AsyncGenerator extends Generator {

	/**
	 * Launch the feed generation process
	 */
	public function launch() {
		$part_size = ShoppingFeedHelper::get_sf_part_size();

		$products       = Products::get_instance()->get_products();
		$total_products = count( $products );
		$total_pages    = 1;
		if ( $part_size < $total_products ) {
			$total_pages = (int) round( $total_products / $part_size );
		}

		$option = array(
			'total_pages' => $total_pages,
		);

		update_option( 'sf_feed_generation_process', $option );
		for ( $page = 1; $page <= $total_pages; $page ++ ) {
			as_schedule_single_action(
				false,
				'sf_feed_generation_part',
				array(
					$page,
					$part_size,
				),
				'sf_feed_generation_process'
			);
		}
	}

	/**
	 * Generate feed part
	 *
	 * @param $page
	 * @param $post_per_page
	 */
	public function generate_feed_part( $page, $post_per_page ) {
		$args     = array(
			'page'  => $page,
			'limit' => $post_per_page,
		);
		$products = Products::get_instance()->get_products( $args );
		$path     = sprintf( '%s/%s', ShoppingFeedHelper::get_feed_parts_directory(), 'part_' . $page );

		$products_list = Products::get_instance()->format_products( $products );

		try {
			$this->generator = new ProductGenerator();
			$this->generator->setPlatform( $page, $page );
			$this->generator->setUri( sprintf( 'file://%s.xml', $path ) );
			$this->set_filters();
			$this->set_mappers();
			$this->generator->write( $products_list );

			$option                = get_option( 'sf_feed_generation_process' );
			$option['currentPage'] = $page;
			update_option( 'sf_feed_generation_process', $option );

			if ( ! empty( $option['currentPage'] ) && $option['currentPage'] === $option['total_pages'] ) {
				as_schedule_single_action(
					false,
					'sf_feed_generation_combine_feed_parts',
					array(),
					'sf_feed_generation_process'
				);
			}
		} catch ( \Exception $exception ) {
			return new \WP_Error( 'shopping_feed_generation_error', $exception->getMessage() );
		}

		return true;
	}

	/**
	 * Combine the generated parts to a unique and final file
	 */
	public function combine_feed_parts() {
		$option = get_option( 'sf_feed_generation_process' );

		$dir       = ShoppingFeedHelper::get_feed_directory();
		$dir_parts = ShoppingFeedHelper::get_feed_parts_directory();

		$files = glob( $dir_parts . '/' . '*.xml' ); // @codingStandardsIgnoreLine.

		$xml_content      = '<products>';
		$xml_invalid      = 0;
		$xml_ignored      = 0;
		$xml_written      = 0;
		$last_started_at  = '';
		$last_finished_at = '';
		foreach ( $files as $file ) {
			$file_xml         = simplexml_load_string( file_get_contents( $file ) );
			$last_started_at  = $file_xml->metadata->startedAt;
			$last_finished_at = $file_xml->metadata->finishedAt;
			$xml_invalid     += (int) $file_xml->metadata->invalid;
			$xml_ignored     += (int) $file_xml->metadata->ignored;
			$xml_written     += (int) $file_xml->metadata->written;
			$products         = $file_xml->products[0];
			foreach ( $products as $product ) {
				$xml_content .= $product->asXML();
			}
			wp_delete_file( $file );
		}
		$xml_content .= '</products>';
		$xml_content = simplexml_load_string( $xml_content );

		/**
		 * Save products tag to a temporary file
		 * Read and get the xml content from the file and remove the xml header
		 * Delete the temporary file
		 */
		$tmp_file_path = $dir . '/products_tmp.xml';
		if ( ! file_put_contents( $tmp_file_path, $xml_content->asXML() ) ) {
			ShoppingFeedHelper::get_logger()->error(
				sprintf(
				/* translators: %s: Action name. */
					__( 'Cant read create temporary file (products_tmp.xml) : %s', 'shopping-feed' ),
					$tmp_file_path
				),
				array(
					'source' => 'shopping-feed',
				)
			);

			return;
		}
		$products = preg_replace( '/<\?xml version="1.0"\?>\n/', '', file_get_contents( $dir . '/products_tmp.xml' ) );
		wp_delete_file( $dir . '/products_tmp.xml' );

		$skelton = simplexml_load_string( ShoppingFeedHelper::get_feed_skeleton() );
		$this->simplexml_import_xml( $skelton->metadata, $products, $before = true );
		$skelton->metadata->startedAt  = $last_started_at;
		$skelton->metadata->finishedAt = $last_finished_at;
		$skelton->metadata->invalid    = $xml_invalid;
		$skelton->metadata->ignored    = $xml_ignored;
		$skelton->metadata->written    = $xml_written;
		$uri                           = Uri::get_full_path();
		if ( ! file_put_contents( $uri, $skelton->asXML() ) ) {
			ShoppingFeedHelper::get_logger()->error(
				sprintf(
				/* translators: %s: Action name. */
					__( 'Cant read create xml file (products.xml) : %s', 'shopping-feed' ),
					$uri
				),
				array(
					'source' => 'shopping-feed',
				)
			);

			return;
		}
		$option['file'] = $uri;
		update_option( 'sf_feed_generation_process', $option );
		update_option( self::SF_FEED_LAST_GENERATION_DATE, date_i18n( 'd/m/Y H:i:m' ) );
	}

	/**
	 * Insert XML into a SimpleXMLElement
	 *
	 * @param \SimpleXMLElement $parent
	 * @param string $xml
	 * @param bool $before
	 *
	 * @return bool XML string added
	 * @see https://gist.github.com/hakre/4761677
	 * @psalm-suppress all
	 */
	public function simplexml_import_xml( \SimpleXMLElement $parent, $xml, $before = false ) {
		$xml = (string) $xml;
		// @codingStandardsIgnoreStart
		// check if there is something to add
		if ( null === $parent[0] || $nodata = ! strlen( $xml ) ) {
			return $nodata;
		}

		$node     = dom_import_simplexml( $parent );
		$fragment = $node->ownerDocument->createDocumentFragment();
		$fragment->appendXML( $xml );

		if ( $before ) {
			return (bool) $node->parentNode->insertBefore( $fragment, $node );
		}

		return (bool) $node->appendChild( $fragment );
		// @codingStandardsIgnoreEnd
	}
}

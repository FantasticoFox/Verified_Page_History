<?php

namespace DataAccounting\API;

use DataAccounting\get_page_all_revs;
use MediaWiki\Rest\SimpleHandler;
use Wikimedia\ParamValidator\ParamValidator;

# include / exclude for debugging
error_reporting( E_ALL );
ini_set( "display_errors", 1 );

require_once( __DIR__ . "/../ApiUtil.php" );

class GetPageAllRevsHandler extends SimpleHandler {

	/** @inheritDoc */
	public function run( $page_title ) {
		#Expects Page Title and returns ALL verified revisions
		return get_page_all_revs( $page_title );
	}

	/** @inheritDoc */
	public function needsWriteAccess() {
		return false;
	}

	/** @inheritDoc */
	public function getParamSettings() {
		return [
			'page_title' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}
}

<?php

namespace DataAccounting\API;

use DataAccounting\Verification\VerificationEngine;
use DataAccounting\Verification\WitnessingEngine;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use RequestContext;
use User;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class WriteStoreWitnessTxHandler extends SimpleHandler {

	/** @var PermissionManager */
	private $permissionManager;
	/** @var VerificationEngine */
	private $verificationEngine;
	/** @var WitnessingEngine */
	private $witnessingEngine;

	/** @var User */
	private $user;

	/**
	 * @param PermissionManager $permissionManager
	 */
	public function __construct(
		PermissionManager $permissionManager,
		VerificationEngine $verificationEngine,
		WitnessingEngine $witnessingEngine
	) {
		$this->permissionManager = $permissionManager;
		$this->verificationEngine = $verificationEngine;
		$this->witnessingEngine = $witnessingEngine;

		// @todo Inject this, when there is a good way to do that
		$this->user = RequestContext::getMain()->getUser();
	}

	/** @inheritDoc */
	public function run() {
		// Only user and sysop have the 'move' right. We choose this so that
		// the DataAccounting extension works as expected even when not run via
		// micro-PKC Docker. As in, it shouldn't depend on the configuration of
		// an external, separate repo.
		if ( !$this->permissionManager->userHasRight( $this->user, 'move' ) ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'rest-permission-denied-revision' )->plaintextParams(
					'You are not allowed to use the REST API'
				),
				403
			);
		}

		$body = $this->getValidatedBody();
		$witness_event_id = $body['witness_event_id'];
		$account_address = $body['account_address'];
		$transaction_hash = $body['transaction_hash'];
		$witnessNetwork = $body['witness_network'];

		$witnessPages = $this->witnessingEngine->getLookup()->pageEntitiesFromWitnessId( $witness_event_id );
		if ( empty( $witnessPages ) ) {
			throw new HttpException( "No revisions are witnessed by given id", 404 );
		}

		/*$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$dbw = $lb->getConnectionRef( DB_PRIMARY );
		$table = 'witness_events';*/

		// Witness ID update rules:
		// - Newer mainnet witness takes precedence over existing testnet witness.
		// - If witness ID exists, don't write the witness_id. If it doesn't
		//   exist, insert the witness_id. This is because the oldest witness has
		//   the biggest value. This proves that the revision has existed earlier.
		foreach ( $witnessPages as $page ) {
			$verificationEntity = $this->verificationEngine->getLookup()->verificationEntityFromHash(
				$page->get( 'revision_verification_hash' )
			);
			if ( $verificationEntity === null) {
				continue;
			}
			if ( $verificationEntity->getWitnessEventID() === 0 ) {
				// If this revisin of the page has been aggregated in the
				// witness_page table, but has never been witnessed before, we
				// expect it to be 0.
				$this->verificationEngine->getLookup()->updateEntity( $verificationEntity, [
					'witness_event_id' => $witness_event_id,
				] );
			} else {
				$pageWitnessEvent = $this->witnessingEngine->getLookup()->witnessEventFromQuery( [
					'witness_event_id' => $verificationEntity->getWitnessEventId(),
				] );
				$previousWitnessNetwork = 'corrupted';
				if ( $pageWitnessEvent ) {
					$previousWitnessNetwork = $pageWitnessEvent->get( 'witness_network' );
				}
				if ( $previousWitnessNetwork !== 'mainnet' && $witnessNetwork === 'mainnet' ) {
					$this->verificationEngine->getLookup()->updateEntity( $verificationEntity, [
						'witness_event_id' => $witness_event_id,
					] );
				}
			}
		}

		$witnessEvent = $this->witnessingEngine->getLookup()->witnessEventFromQuery( [
			'witness_event_id' => $witness_event_id
		] );
		if ( !$witnessEvent ) {
			throw new HttpException( "witness_event_id not found in the witness_events table.", 404 );
		}

		$witnessHash = $this->verificationEngine->getHasher()->getHashSum(
			$witnessEvent->get( 'domain_manifest_genesis_hash' ) .
			$witnessEvent->get( 'merkle_root' ) .
			$witnessEvent->get( 'witness_network' ) .
			$transaction_hash
		);

		/** write data to database */
		// Write the witness_hash into the witness_events table
		$this->witnessingEngine->getLookup()->updateWitnessEventEntity( $witnessEvent, [
			'sender_account_address' => $account_address,
			'witness_event_transaction_hash' => $transaction_hash,
			'source' => 'default',
			'witness_hash' => $witnessHash,
		] );

		// Patch witness data into domain manifest page.
		$domainManifestPage = $this->verificationEngine->getLookup()->verificationEntityFromHash(
			$witnessEvent->get( 'domain_manifest_genesis_hash' )
		);
		if ( !$domainManifestPage ) {
			throw new HttpException( "No Domain manifest page found", 404 );
		}
		$this->verificationEngine->getLookup()->updateEntity( $domainManifestPage, [
			'witness_event_id' => $witness_event_id,
		] );

		// Add receipt to the domain manifest
		$this->witnessingEngine->addReceiptToDomainManifest( $this->user, $witnessEvent );

		return true;
	}

	/** @inheritDoc */
	public function needsWriteAccess() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getBodyValidator( $contentType ) {
		if ( $contentType !== 'application/json' ) {
			throw new HttpException( "Unsupported Content-Type",
				415,
				[ 'content_type' => $contentType ]
			);
		}

		return new JsonBodyValidator( [
			'witness_event_id' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'account_address' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'transaction_hash' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'witness_network' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		] );
	}
}

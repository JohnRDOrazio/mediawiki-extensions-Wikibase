<?php

declare( strict_types = 1 );

namespace Wikibase\Repo\Api;

use ApiBase;
use ApiMain;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Services\Statement\StatementGuidParser;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\Repo\ChangeOp\ChangeOp;
use Wikibase\Repo\ChangeOp\StatementChangeOpFactory;
use Wikibase\Repo\WikibaseRepo;

/**
 * API module for creating a qualifier or setting the value of an existing one.
 *
 * @license GPL-2.0-or-later
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 * @author Daniel Kinzler
 * @author Tobias Gritschacher < tobias.gritschacher@wikimedia.de >
 */
class SetQualifier extends ApiBase {

	use FederatedPropertyApiValidatorTrait;

	/**
	 * @var StatementChangeOpFactory
	 */
	private $statementChangeOpFactory;

	/**
	 * @var ApiErrorReporter
	 */
	protected $errorReporter;

	/**
	 * @var StatementModificationHelper
	 */
	private $modificationHelper;

	/**
	 * @var StatementGuidParser
	 */
	private $guidParser;

	/**
	 * @var ResultBuilder
	 */
	private $resultBuilder;

	/**
	 * @var EntitySavingHelper
	 */
	private $entitySavingHelper;

	/**
	 * @param ApiMain $mainModule
	 * @param string $moduleName
	 * @param callable $errorReporterInstantiator
	 * @param StatementChangeOpFactory $statementChangeOpFactory
	 * @param StatementModificationHelper $modificationHelper
	 * @param StatementGuidParser $guidParser
	 * @param callable $resultBuilderInstantiator
	 * @param callable $entitySavingHelperInstantiator
	 *
	 * @note Using callable for several arguments because of circular dependency and unability to inject object to constructor
	 */
	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		callable $errorReporterInstantiator,
		StatementChangeOpFactory $statementChangeOpFactory,
		StatementModificationHelper $modificationHelper,
		StatementGuidParser $guidParser,
		callable $resultBuilderInstantiator,
		callable $entitySavingHelperInstantiator,
		bool $federatedPropertiesEnabled
	) {
		parent::__construct( $mainModule, $moduleName );

		$this->errorReporter = $errorReporterInstantiator( $this );
		$this->statementChangeOpFactory = $statementChangeOpFactory;

		$this->modificationHelper = $modificationHelper;
		$this->guidParser = $guidParser;
		$this->resultBuilder = $resultBuilderInstantiator( $this );
		$this->entitySavingHelper = $entitySavingHelperInstantiator( $this );
		$this->federatedPropertiesEnabled = $federatedPropertiesEnabled;
	}

	public static function factory( ApiMain $mainModule, string $moduleName ): self {
		$wikibaseRepo = WikibaseRepo::getDefaultInstance();
		$apiHelperFactory = $wikibaseRepo->getApiHelperFactory( $mainModule->getContext() );
		$changeOpFactoryProvider = $wikibaseRepo->getChangeOpFactoryProvider();

		$modificationHelper = new StatementModificationHelper(
			$wikibaseRepo->getSnakFactory(),
			$wikibaseRepo->getEntityIdParser(),
			$wikibaseRepo->getStatementGuidValidator(),
			$apiHelperFactory->getErrorReporter( $mainModule )
		);

		return new self(
			$mainModule,
			$moduleName,
			function ( $module ) use ( $apiHelperFactory ) {
				return $apiHelperFactory->getErrorReporter( $module );
			},
			$changeOpFactoryProvider->getStatementChangeOpFactory(),
			$modificationHelper,
			$wikibaseRepo->getStatementGuidParser(),
			function ( $module ) use ( $apiHelperFactory ) {
				return $apiHelperFactory->getResultBuilder( $module );
			},
			function ( $module ) use ( $apiHelperFactory ) {
				return $apiHelperFactory->getEntitySavingHelper( $module );
			},
			$wikibaseRepo->inFederatedPropertyMode()
		);
	}

	/**
	 * @inheritDoc
	 */
	public function execute(): void {
		$params = $this->extractRequestParams();
		$this->validateParameters( $params );

		$entityId = $this->guidParser->parse( $params['claim'] )->getEntityId();
		$this->validateAlteringEntityById( $entityId );

		$entity = $this->entitySavingHelper->loadEntity( $entityId );

		$summary = $this->modificationHelper->createSummary( $params, $this );

		$statement = $this->modificationHelper->getStatementFromEntity( $params['claim'], $entity );

		if ( isset( $params['snakhash'] ) ) {
			$this->validateQualifierHash( $statement, $params['snakhash'] );
		}

		$changeOp = $this->getChangeOp();
		$this->modificationHelper->applyChangeOp( $changeOp, $entity, $summary );

		$status = $this->entitySavingHelper->attemptSaveEntity( $entity, $summary );
		$this->resultBuilder->addRevisionIdFromStatusToResult( $status, 'pageinfo' );
		$this->resultBuilder->markSuccess();
		$this->resultBuilder->addStatement( $statement );
	}

	/**
	 * Checks if the required parameters are set and the ones that make no sense given the
	 * snaktype value are not set.
	 *
	 * @param array $params
	 */
	private function validateParameters( array $params ): void {
		if ( !( $this->modificationHelper->validateStatementGuid( $params['claim'] ) ) ) {
			$this->errorReporter->dieError( 'Invalid claim guid', 'invalid-guid' );
		}

		if ( !isset( $params['snakhash'] ) ) {
			if ( !isset( $params['snaktype'] ) ) {
				$this->errorReporter->dieWithError( [ 'param-missing', 'snaktype' ],
					'param-missing'
				);
			}

			if ( !isset( $params['property'] ) ) {
				$this->errorReporter->dieWithError( [ 'param-missing', 'property' ],
					'param-missing'
				);
			}
		}

		if ( isset( $params['snaktype'] ) && $params['snaktype'] === 'value' && !isset( $params['value'] ) ) {
			$this->errorReporter->dieWithError( [ 'param-missing', 'value' ],
				'param-missing'
			);
		}
	}

	private function validateQualifierHash( Statement $statement, string $qualifierHash ): void {
		if ( !$statement->getQualifiers()->hasSnakHash( $qualifierHash ) ) {
			$this->errorReporter->dieError(
				'Claim does not have a qualifier with the given hash',
				'no-such-qualifier'
			);
		}
	}

	private function getChangeOp(): ChangeOp {
		$params = $this->extractRequestParams();

		$guid = $params['claim'];

		$propertyId = $this->modificationHelper->getEntityIdFromString( $params['property'] );
		if ( !( $propertyId instanceof PropertyId ) ) {
			$this->errorReporter->dieWithError(
				[ 'wikibase-api-invalid-property-id', $propertyId->getSerialization() ],
				'param-illegal'
			);
		}
		$newQualifier = $this->modificationHelper->getSnakInstance( $params, $propertyId );

		$snakHash = $params['snakhash'] ?? '';
		$changeOp = $this->statementChangeOpFactory->newSetQualifierOp( $guid, $newQualifier, $snakHash );

		return $changeOp;
	}

	/**
	 * @inheritDoc
	 */
	public function isWriteMode(): bool {
		return true;
	}

	/**
	 * @see ApiBase::needsToken
	 *
	 * @return string
	 */
	public function needsToken(): string {
		return 'csrf';
	}

	/**
	 * @inheritDoc
	 */
	protected function getAllowedParams(): array {
		return array_merge(
			[
				'claim' => [
					self::PARAM_TYPE => 'string',
					self::PARAM_REQUIRED => true,
				],
				'property' => [
					self::PARAM_TYPE => 'string',
					self::PARAM_REQUIRED => false,
				],
				'value' => [
					self::PARAM_TYPE => 'text',
					self::PARAM_REQUIRED => false,
				],
				'snaktype' => [
					self::PARAM_TYPE => [ 'value', 'novalue', 'somevalue' ],
					self::PARAM_REQUIRED => false,
				],
				'snakhash' => [
					self::PARAM_TYPE => 'string',
					self::PARAM_REQUIRED => false,
				],
				'summary' => [
					self::PARAM_TYPE => 'string',
				],
				'tags' => [
					self::PARAM_TYPE => 'tags',
					self::PARAM_ISMULTI => true,
				],
				'token' => null,
				'baserevid' => [
					self::PARAM_TYPE => 'integer',
				],
				'bot' => false,
			],
			parent::getAllowedParams()
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function getExamplesMessages(): array {
		return [
			'action=wbsetqualifier&claim=Q2$4554c0f4-47b2-1cd9-2db9-aa270064c9f3&property=P1'
				. '&value="GdyjxP8I6XB3"&snaktype=value&token=foobar'
				=> 'apihelp-wbsetqualifier-example-1',
		];
	}

}

<?php

/**
 * @file
 * Contains \Drupal\search_api_solr\Tests\SearchApiSolrTest.
 */

namespace Drupal\Tests\search_api_solr\Kernel;

use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\search_api\Utility;
use Drupal\Tests\search_api_db\Kernel\BackendTest;

/**
 * Tests index and search capabilities using the Solr search backend.
 *
 * @group search_api_solr
 */
class SearchApiSolrTest extends BackendTest {

  /**
   * A Search API server ID.
   *
   * @var string
   */
  protected $serverId = 'solr_search_server';

  /**
   * A Search API index ID.
   *
   * @var string
   */
  protected $indexId = 'solr_search_index';

  /**
   * Whether a Solr core is available for testing. Mostly needed because Drupal
   * testbots do not support this.
   *
   * @var bool
   */
  protected $solrAvailable = FALSE;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('search_api_solr', 'search_api_test_solr');

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
    // @todo For some reason the init event (see AutoloaderSubscriber) is not
    // working in command line tests
    $filepath = drupal_get_path('module', 'search_api_solr') . '/vendor/autoload.php';
    if (!class_exists('Solarium\\Client') && ($filepath != DRUPAL_ROOT . '/core/vendor/autoload.php')) {
      require $filepath;
    }

    $this->installConfig(array('search_api_test_solr'));

    // Because this is a kernel test, the routing isn't built by default, so
    // we have to force it.
    \Drupal::service('router.builder')->rebuild();

    try {
      /** @var \Drupal\search_api\ServerInterface $server */
      $server = Server::load($this->serverId);
      if ($server->getBackend()->ping()) {
        $this->solrAvailable = TRUE;
      }
    }
    catch (\Exception $e) {}
  }

  /**
   * Clear the index after every test.
   */
  public function tearDown() {
    $this->clearIndex();
    parent::tearDown();
  }

  /**
   * Tests various indexing scenarios for the Solr search backend.
   */
  public function testFramework() {
    // Only run the tests if we have a Solr core available.
    if ($this->solrAvailable) {
      parent::testFramework();
    }
    else {
      $this->assertTrue(TRUE, 'Error: The Solr instance could not be found. Please enable a multi-core one on http://localhost:8983/solr/d8');
    }
  }

  /**
   * Tests facets.
   */
  public function testFacets() {
    $this->insertExampleContent();
    $this->indexItems($this->indexId);

    // Create a query object.
    $query = Utility::createQuery($this->getIndex());

    // Add a condition on the query object, to filter on category.
    $conditions = $query->createConditionGroup('OR', array('facet:category'));
    $conditions->addCondition('category', 'article_category');
    $query->addConditionGroup($conditions);

    // Add facet to the query.
    $facets['category'] = array(
      'field' => 'category',
      'limit' => 10,
      'min_count' => 1,
      'missing' => TRUE,
      'operator' => 'or',
    );
    $query->setOption('search_api_facets', $facets);

    // Get the result.
    $results = $query->execute();

    $expected_results = array(
      'entity:entity_test/4:en',
      'entity:entity_test/5:en',
    );

    // Asserts that the result count is correct, as well as that the entities 4
    // and 5 returned. And that the added condition actually filtered out the
    // results so that the category of the returned results is article_category.
    $this->assertEquals($expected_results, array_keys($results->getResultItems()));
    $this->assertEquals(array('article_category'), $results->getResultItems()['entity:entity_test/4:en']->getField('category')->getValues());
    $this->assertEquals(array('article_category'), $results->getResultItems()['entity:entity_test/5:en']->getField('category')->getValues());
    $this->assertEquals(2, $results->getResultCount(), 'OR facets query returned correct number of results.');

    $expected = array(
      array('count' => 2, 'filter' => '"article_category"'),
      array('count' => 2, 'filter' => '"item_category"'),
      array('count' => 1, 'filter' => '!'),
    );
    $category_facets = $results->getExtraData('search_api_facets')['category'];
    usort($category_facets, array($this, 'facetCompare'));

    // Asserts that the returned facets are those that we expected.
    $this->assertEquals($expected, $category_facets, 'Correct OR facets were returned');
  }

  /**
   * {@inheritdoc}
   */
  protected function indexItems($index_id) {
    $index_status = parent::indexItems($index_id);
    sleep(2);
    return $index_status;
  }

  /**
   * {@inheritdoc}
   */
  protected function clearIndex() {
    /** @var \Drupal\search_api\IndexInterface $index */
    $index = Index::load($this->indexId);
    $index->clear();
    // Deleting items take at least 1 second for Solr to parse it so that drupal
    // doesn't get timeouts while waiting for Solr. Lets give it 2 seconds to
    // make sure we are in bounds.
    sleep(2);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkServerTables() {
    // The Solr backend doesn't create any database tables.
  }

  protected function updateIndex() {
    // The parent assertions don't make sense for the Solr backend.
  }

  protected function editServer() {
    // The parent assertions don't make sense for the Solr backend.
  }

  /**
   * {@inheritdoc}
   */
  protected function searchSuccess2() {
    // This method tests the 'min_chars' option of the Database backend, which
    // we don't have in Solr.
    // @todo Copy tests from the Apachesolr module which create Solr cores on
    // the fly with various schemas.
  }

  /**
   * {@inheritdoc}
   */
  protected function checkModuleUninstall() {
    // See whether clearing the server works.
    // Regression test for #2156151.
    $server = Server::load($this->serverId);
    $index = Index::load($this->indexId);
    $server->deleteAllItems($index);
    // Deleting items take at least 1 second for Solr to parse it so that drupal
    // doesn't get timeouts while waiting for Solr. Lets give it 2 seconds to
    // make sure we are in bounds.
    sleep(2);
    $query = $this->buildSearch();
    $results = $query->execute();
    $this->assertEquals(0, $results->getResultCount(), 'Clearing the server worked correctly.');
  }

  /**
   * {@inheritdoc}
   */
  protected function assertIgnored(ResultSetInterface $results, array $ignored = array(), $message = 'No keys were ignored.') {
    // Nothing to do here since the Solr backend doesn't keep a list of ignored
    // fields.
  }

}

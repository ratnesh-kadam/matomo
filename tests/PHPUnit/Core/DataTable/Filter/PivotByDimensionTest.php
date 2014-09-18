<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
namespace Piwik\Tests\Core\DataTable\Filter;

use Piwik\API\Proxy;
use Piwik\DataTable;
use Piwik\DataTable\Filter\PivotByDimension;
use Piwik\DataTable\Row;
use Piwik\Plugin\Manager as PluginManager;
use PHPUnit_Framework_TestCase;
use Exception;

/**
 * @group Core
 * @group PivotByDimensionTest
 */
class PivotByDimensionTest extends PHPUnit_Framework_TestCase
{
    /**
     * The number of segment tables that have been created. Used when injecting API results to make sure each
     * segment table is different.
     *
     * @var int
     */
    private $segmentTableCount;

    public function setUp()
    {
        $self = $this;

        $proxyMock = $this->getMock('stdClass', array('call'));
        $proxyMock->expects($this->any())->method('call')->willReturnCallback(function ($className, $methodName, $parameters) use ($self) {
            if ($className == "\\Piwik\\Plugins\\UserCountry\\API"
                && $methodName == 'getCity'
            ) {
                return $self->getSegmentTable();
            } else {
                throw new Exception("Unknown API request: $className::$methodName.");
            }
        });
        Proxy::setSingletonInstance($proxyMock);

        $this->segmentTableCount = 0;
    }

    public function tearDown()
    {
        PluginManager::unsetInstance();
        Proxy::unsetInstance();
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Unsupported pivot: report 'ExampleReport.ExampleReportName' has no subtable dimension.
     */
    public function testConstructionFailsWhenReportHasNoSubtableAndSegmentFetchingIsDisabled()
    {
        PluginManager::getInstance()->loadPlugins(array('ExampleReport', 'UserCountry'));

        new PivotByDimension(new DataTable(), "ExampleReport.GetExampleReport", "UserCountry.City", 'nb_visits', $columnLimit = -1, $enableFetchBySegment = false);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Unsupported pivot: the subtable dimension for 'Referrers.Referrers_Keywords' does not match the requested pivotBy dimension.
     */
    public function testConstructionFailsWhenDimensionIsNotSubtableAndSegmentFetchingIsDisabled()
    {
        PluginManager::getInstance()->loadPlugins(array('Referrers', 'UserCountry'));

        new PivotByDimension(new DataTable(), "Referrers.getKeywords", "UserCountry.City", "nb_visits", $columnLimit = -1, $enableFetchBySegment = false);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Unsupported pivot: No segment for dimension of report 'UserSettings.UserSettings_WidgetBrowserFamilies'
     */
    public function testConstructionFailsWhenDimensionIsNotSubtableAndSegmentFetchingIsEnabledButThereIsNoSegment()
    {
        PluginManager::getInstance()->loadPlugins(array('Referrers', 'UserSettings'));

        new PivotByDimension(new DataTable(), "UserSettings.getBrowserType", "Referrers.Keyword", "nb_visits");
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Invalid dimension 'ExampleTracker.InvalidDimension'
     */
    public function testConstructionFailsWhenDimensionDoesNotExist()
    {
        PluginManager::getInstance()->loadPlugins(array('ExampleReport', 'ExampleTracker'));

        new PivotByDimension(new DataTable(), "ExampleReport.GetExampleReport", "ExampleTracker.InvalidDimension", 'nb_visits');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Unsupported pivot: No report for pivot dimension 'ExampleTracker.ExampleDimension'
     */
    public function testConstructionFailsWhenThereIsNoReportForADimension()
    {
        PluginManager::getInstance()->loadPlugins(array('ExampleReport', 'ExampleTracker'));

        new PivotByDimension(new DataTable(), "ExampleReport.GetExampleReport", "ExampleTracker.ExampleDimension", "nb_visits");
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Unable to find report 'ExampleReport.InvalidReport'
     */
    public function testConstructionFailsWhenSpecifiedReportIsNotValid()
    {
        PluginManager::getInstance()->loadPlugins(array('ExampleReport', 'Referrers'));

        new PivotByDimension(new DataTable(), "ExampleReport.InvalidReport", "Referrers.Keyword", "nb_visits");
    }

    public function testFilterReturnsEmptyResultWhenTableToFilterIsEmpty()
    {
        PluginManager::getInstance()->loadPlugins(array('Referrers', 'UserCountry', 'CustomVariables'));

        $table = new DataTable();

        $pivotFilter = new PivotByDimension($table, "Referrers.getKeywords", "Referrers.SearchEngine", 'nb_visits');
        $pivotFilter->filter($table);

        $expectedRows = array();
        $this->assertEquals($expectedRows, $table->getRows());
    }

    public function testFilterCorrectlyCreatesPivotTableUsingSubtableReport()
    {
        PluginManager::getInstance()->loadPlugins(array('Referrers', 'UserCountry', 'CustomVariables'));

        $table = $this->getTableToFilter(true);

        $pivotFilter = new PivotByDimension($table, "Referrers.getKeywords", "Referrers.SearchEngine", 'nb_actions', $columnLimit = -1, $fetchBySegment = false);
        $pivotFilter->filter($table);

        $expectedRows = array(
            array('label' => 'row 1', 'col 1' => 2, 'col 2' => false, 'col 3' => false, 'col 4' => false),
            array('label' => 'row 2', 'col 1' => 4, 'col 2' => 6, 'col 3' => false, 'col 4' => false),
            array('label' => 'row 3', 'col 1' => false, 'col 2' => 8, 'col 3' => 31, 'col 4' => 33)
        );
        $this->assertTableRowsEquals($expectedRows, $table);
    }

    public function testFilterCorrectlyCreatesPivotTableUsingSegment()
    {
        PluginManager::getInstance()->loadPlugins(array('Referrers', 'UserCountry', 'CustomVariables'));

        $table = $this->getTableToFilter(true);

        $pivotFilter = new PivotByDimension($table, "Referrers.getKeywords", "UserCountry.City", 'nb_visits');
        $pivotFilter->filter($table);

        $expectedRows = array(
            array('label' => 'row 1', 'col 0' => 2, 'col 1' => false, 'col 2' => false),
            array('label' => 'row 2', 'col 0' => 2, 'col 1' => 4, 'col 2' => false),
            array('label' => 'row 3', 'col 0' => 2, 'col 1' => 4, 'col 2' => 6)
        );
        $this->assertTableRowsEquals($expectedRows, $table);
    }

    public function testFilterCorrectlyCreatesPivotTableWhenPivotMetricDoesNotExistInTable()
    {
        PluginManager::getInstance()->loadPlugins(array('Referrers', 'UserCountry', 'CustomVariables'));

        $table = $this->getTableToFilter(true);

        $pivotFilter = new PivotByDimension($table, "Referrers.getKeywords", "Referrers.SearchEngine", 'invalid_metric');
        $pivotFilter->filter($table);

        $expectedRows = array(
            array('label' => 'row 1', 'col 1' => false, 'col 2' => false, 'col 3' => false, 'col 4' => false),
            array('label' => 'row 2', 'col 1' => false, 'col 2' => false, 'col 3' => false, 'col 4' => false),
            array('label' => 'row 3', 'col 1' => false, 'col 2' => false, 'col 3' => false, 'col 4' => false)
        );
        $this->assertTableRowsEquals($expectedRows, $table);
    }

    public function testFilterCorrectlyCreatesPivotTableWhenSubtablesHaveNoRows()
    {
        PluginManager::getInstance()->loadPlugins(array('Referrers', 'UserCountry', 'CustomVariables'));

        $table = $this->getTableToFilter(false);

        $pivotFilter = new PivotByDimension($table, "CustomVariables.getCustomVariables", "CustomVariables.CustomVariableValue",
            'nb_visits', $fetchBySegment = false);
        $pivotFilter->filter($table);

        $expectedRows = array(
            array('label' => 'row 1'),
            array('label' => 'row 2'),
            array('label' => 'row 3')
        );
        $this->assertTableRowsEquals($expectedRows, $table);
    }

    public function testFilterCorrectlyDefaultsPivotByColumnWhenNoneProvided()
    {
        PluginManager::getInstance()->loadPlugins(array('Referrers', 'UserCountry', 'CustomVariables'));

        $table = $this->getTableToFilter(true);

        $pivotFilter = new PivotByDimension($table, "Referrers.getKeywords", "Referrers.SearchEngine", $column = false, $columnLimit = -1, $fetchBySegment = false);
        $pivotFilter->filter($table);

        $expectedRows = array(
            array('label' => 'row 1', 'col 1' => 1, 'col 2' => false, 'col 3' => false, 'col 4' => false),
            array('label' => 'row 2', 'col 1' => 3, 'col 2' => 5, 'col 3' => false, 'col 4' => false),
            array('label' => 'row 3', 'col 1' => false, 'col 2' => 7, 'col 3' => 9, 'col 4' => 32)
        );
        $this->assertTableRowsEquals($expectedRows, $table);
    }

    public function testFilterCorrectlyLimitsTheColumnNumberWhenColumnLimitProvided()
    {
        PluginManager::getInstance()->loadPlugins(array('Referrers', 'UserCountry', 'CustomVariables'));

        $table = $this->getTableToFilter(true);

        $pivotFilter = new PivotByDimension($table, "Referrers.getKeywords", "Referrers.SearchEngine", $column = 'nb_visits', $columnLimit = 3, $fetchBySegment = false);
        $pivotFilter->filter($table);

        $expectedRows = array(
            array('label' => 'row 1', 'col 2' => false, 'col 3' => false, 'col 4' => false),
            array('label' => 'row 2', 'col 2' => 5, 'col 3' => false, 'col 4' => false),
            array('label' => 'row 3', 'col 2' => 7, 'col 3' => 9, 'col 4' => 32)
        );
        $this->assertTableRowsEquals($expectedRows, $table);
    }

    private function getTableToFilter($addSubtables = false)
    {
        $row1 = new Row(array(Row::COLUMNS => array(
            'label' => 'row 1',
            'nb_visits' => 10,
            'nb_actions' => 15
        )));
        if ($addSubtables) {
            $row1->setSubtable($this->getRow1Subtable());
        }

        $row2 = new Row(array(Row::COLUMNS => array(
            'label' => 'row 2',
            'nb_visits' => 13,
            'nb_actions' => 18
        )));
        if ($addSubtables) {
            $row2->setSubtable($this->getRow2Subtable());
        }

        $row3 = new Row(array(Row::COLUMNS => array(
            'label' => 'row 3',
            'nb_visits' => 20,
            'nb_actions' => 25
        )));
        if ($addSubtables) {
            $row3->setSubtable($this->getRow3Subtable());
        }

        $table = new DataTable();
        $table->addRowsFromArray(array($row1, $row2, $row3));
        return $table;
    }

    private function getRow1Subtable()
    {
        $table = new DataTable();
        $table->addRowsFromArray(array(
            new Row(array(Row::COLUMNS => array(
                'label' => 'col 1',
                'nb_visits' => 1,
                'nb_actions' => 2
            )))
        ));
        return $table;
    }

    private function getRow2Subtable()
    {
        $table = new DataTable();
        $table->addRowsFromArray(array(
            new Row(array(Row::COLUMNS => array(
                'label' => 'col 1',
                'nb_visits' => 3,
                'nb_actions' => 4
            ))),
            new Row(array(Row::COLUMNS => array(
                'label' => 'col 2',
                'nb_visits' => 5,
                'nb_actions' => 6
            )))
        ));
        return $table;
    }

    private function getRow3Subtable()
    {
        $table = new DataTable();
        $table->addRowsFromArray(array(
            new Row(array(Row::COLUMNS => array(
                'label' => 'col 2',
                'nb_visits' => 7,
                'nb_actions' => 8
            ))),
            new Row(array(Row::COLUMNS => array(
                'label' => 'col 3',
                'nb_visits' => 9,
                'nb_actions' => 31
            ))),
            new Row(array(Row::COLUMNS => array(
                'label' => 'col 4',
                'nb_visits' => 32,
                'nb_actions' => 33
            )))
        ));
        return $table;
    }

    private function getSegmentTable()
    {
        ++$this->segmentTableCount;

        $table = new DataTable();
        for ($i = 0; $i != $this->segmentTableCount; ++$i) {
            $row = new Row(array(Row::COLUMNS => array(
                'label' => 'col ' . $i,
                'nb_visits' => ($i + 1) * 2,
                'nb_actions' => ($i + 1) * 3
            )));
            $table->addRow($row);
        }
        return $table;
    }

    private function assertTableRowsEquals($expectedRows, $table)
    {
        $renderer = new DataTable\Renderer\Php();
        $renderer->setSerialize(false);
        $actualRows = $renderer->render($table);

        $this->assertEquals($expectedRows, $actualRows);
    }
}
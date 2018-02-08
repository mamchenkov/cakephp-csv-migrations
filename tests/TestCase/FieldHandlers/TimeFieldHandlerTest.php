<?php
namespace CsvMigrations\Test\TestCase\FieldHandlers;

use Cake\I18n\Date;
use Cake\I18n\Time;
use CsvMigrations\FieldHandlers\CsvField;
use CsvMigrations\FieldHandlers\TimeFieldHandler;
use PHPUnit_Framework_TestCase;

class TimeFieldHandlerTest extends PHPUnit_Framework_TestCase
{
    protected $table = 'Fields';
    protected $field = 'field_time';

    protected $fh;

    protected function setUp()
    {
        $this->fh = new TimeFieldHandler($this->table, $this->field);
    }

    public function testInterface()
    {
        $implementedInterfaces = array_keys(class_implements($this->fh));
        $this->assertTrue(in_array('CsvMigrations\FieldHandlers\FieldHandlerInterface', $implementedInterfaces), "FieldHandlerInterface is not implemented");
    }

    public function testFieldToDb()
    {
        $csvField = new CsvField(['name' => $this->field, 'type' => 'text']);
        $fh = $this->fh;
        $result = $fh::fieldToDb($csvField);

        $this->assertTrue(is_array($result), "fieldToDb() did not return an array");
        $this->assertFalse(empty($result), "fieldToDb() returned an empty array");
        $this->assertTrue(array_key_exists($this->field, $result), "fieldToDb() did not return field key");
        $this->assertTrue(is_object($result[$this->field]), "fieldToDb() did not return object value for field key");
        $this->assertTrue(is_a($result[$this->field], 'CsvMigrations\FieldHandlers\DbField'), "fieldToDb() did not return DbField instance for field key");

        $this->assertEquals(TimeFieldHandler::getDbFieldType($this->field), $result[$this->field]->getType(), "fieldToDb() did not return correct type for DbField instance");
        $this->assertEquals('time', $result[$this->field]->getType(), "fieldToDb() did not return correct hardcoded type for DbField instance");
    }

    public function getValues()
    {
        return [
            ['2017-07-06 14:20:00', '2017-07-06 14:20:00', 'Date time string'],
            ['2017-07-06', '2017-07-06', 'Date string'],
            ['14:20:00', '14:20:00', 'Time string'],
            ['foobar', 'foobar', 'Non-date string'],
            [15, 15, 'Non-date integer'],
            [Time::parse('2017-07-06 14:20:00'), '14:20', 'Time from object'],
        ];
    }

    /**
     * @dataProvider getValues
     */
    public function testRenderValue($value, $expected, $description)
    {
        $result = $this->fh->renderValue($value, []);
        $this->assertEquals($expected, $result, "Value rendering is broken for: $description");
    }

    public function testRenderInput()
    {
        $result = $this->fh->renderInput('13:30');
        $this->assertRegExp('/field_time/', $result, "Input rendering does not contain field name");
    }

    public function testRenderInputWithTimeObject()
    {
        $result = $this->fh->renderInput(new Time('13:30'));

        $this->assertContains('name="' . $this->table . '[' . $this->field . ']"', $result);
        $this->assertContains('value="13:30"', $result);
        $this->assertContains('data-provide="timepicker"', $result);
    }

    public function testRenderInputWithDateObject()
    {
        $result = $this->fh->renderInput(new Date('13:30'));

        $this->assertContains('value="13:30"', $result);
    }

    public function testGetSearchOptions()
    {
        $result = $this->fh->getSearchOptions();

        $this->assertTrue(is_array($result), "getSearchOptions() did not return an array");
        $this->assertFalse(empty($result), "getSearchOptions() returned an empty result");

        $this->assertArrayHasKey($this->field, $result, "getSearchOptions() did not return field key");

        $this->assertArrayHasKey('type', $result[$this->field], "getSearchOptions() did not return 'type' key");
        $this->assertArrayHasKey('label', $result[$this->field], "getSearchOptions() did not return 'label' key");
        $this->assertArrayHasKey('operators', $result[$this->field], "getSearchOptions() did not return 'operators' key");
        $this->assertArrayHasKey('input', $result[$this->field], "getSearchOptions() did not return 'input' key");

        $this->assertArrayHasKey('is', $result[$this->field]['operators'], "getSearchOptions() did not return 'is' operator");
        $this->assertArrayHasKey('is_not', $result[$this->field]['operators'], "getSearchOptions() did not return 'is_not' operator");
        $this->assertArrayHasKey('greater', $result[$this->field]['operators'], "getSearchOptions() did not return 'greater' operator");
        $this->assertArrayHasKey('less', $result[$this->field]['operators'], "getSearchOptions() did not return 'less' operator");
    }
}

<?php

namespace CsvMigrations\Test\TestCase\Utility;

use Cake\TestSuite\TestCase;
use CsvMigrations\Utility\Panel;

class PanelTest extends TestCase
{
    protected $config;

    public function setUp(): void
    {
        parent::setUp();

        $this->config = [
            'panels' => [
                'Foobar' => "(%%type%% == 'foobar' && %%name%% == 'antonis')",
            ],
        ];
    }

    public function tearDown(): void
    {
        unset($this->config);

        parent::tearDown();
    }

    public function testgetName(): void
    {
        $panel = new Panel('Foobar', $this->config);
        $this->assertEquals('Foobar', $panel->getName());
    }

    /**
     * @expectedException RuntimeException
     */
    public function testSetNameException(): void
    {
        $panel = new Panel('Foobar', $this->config);
        $panel->setName();
    }

    public function testGetExpression(): void
    {
        $panel = new Panel('Foobar', $this->config);
        $result = $panel->getExpression();

        $this->assertFalse(empty($result), 'Expression is empty');
        $this->assertTrue(is_string($result), 'Expression is not a string`');
        $this->assertContains(Panel::EXP_TOKEN, $result, 'Expression does not contain tokens');

        // Cleaned up expression
        $result = $panel->getExpression(true);
        $this->assertNotContains(Panel::EXP_TOKEN, $result, 'Expression contains tokens');
    }

    public function testGetFields(): void
    {
        $panel = new Panel('Foobar', $this->config);
        $result = $panel->getFields();
        $this->assertFalse(empty($result), 'Fields are empty');
        $this->assertTrue(is_array($result), 'Fields is not an array');

        $this->assertTrue(in_array('type', $panel->getFields()), 'Field type is missing');
        $this->assertTrue(in_array('name', $panel->getFields()), 'Field name is missing');
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testSetFields(): void
    {
        $panel = new Panel('Foobar', ['panels' => ['Foobar' => '(this is not a valid expression)']]);
    }

    public function testGetFieldValues(): void
    {
        $panel = new Panel('Foobar', $this->config);
        $data = ['type' => 'company', 'name' => 'amazon', 'dummy' => 'foo'];
        $actual = $panel->getFieldValues($data);
        $expected = ['type' => 'company', 'name' => 'amazon'];
        $this->assertEquals($actual, $expected, 'Data and field values should be equal.');

        $data = [];
        $actual = $panel->getFieldValues($data);
        $expected = ['type' => null, 'name' => null];
        $this->assertEquals($actual, $expected, 'When data is empty the the field values should be null.');
    }

    /**
     * @dataProvider evalExpressionScenariosProvider
     * @param mixed[] $data
     */
    public function testEvalExpression(string $scenario, string $expression, array $data, bool $expected): void
    {
        $panelName = 'Foobar';
        $config['panels']['Foobar'] = $expression;
        $panel = new Panel($panelName, $config);
        $this->assertEquals($expected, $panel->evalExpression($data), sprintf('%s - Expression evaluation failed', $scenario));
        unset($panel);
    }

    /**
     * @return mixed[]
     */
    public function evalExpressionScenariosProvider(): array
    {
        return [
            [
                'Scenario 1 - string comparison',
                "%%status%% == 'first attempt'",
                ['status' => 'first attempt'],
                true
            ],
            [
                'Scenario 2 - string with special character',
                "%%status%% == 'attempt #1'",
                ['status' => 'attempt #1'],
                true
            ],
            [
                'Scenario 3 - logical operator AND',
                "%%status%% == 'active' && %%active%% == false",
                ['status' => 'active', 'active' => false],
                true
            ],
            [
                'Scenario 4 - logical operator NOT',
                "!(%%status%% == 'active')",
                ['status' => 'deactive'],
                true
            ],
            [
                'Scenario 5 - logical operator OR',
                "%%status%% == 'active' || %%active%% == false",
                ['status' => 'active', 'active' => true],
                true
            ],
        ];
    }

    public function testGetPanels(): void
    {
        $config['panels']['Company'] = "(%%type%% == 'individual')";
        $config['panels']['Personal'] = "(%%type%% == 'company')";
        $actual = Panel::getPanelNames($config);
        $expected = ['Company', 'Personal'];

        $this->assertEquals($expected, $actual, 'Does not return the actual panel names from config.');

        $config = [];
        $actual = Panel::getPanelNames($config);
        $expected = [];

        $this->assertEquals($expected, $actual, 'On an empty config, the function should return an empty array.');
    }

    /**
     * Test expression evaluation with extra objects.
     *
     * @dataProvider evalExpressionScenariosWithExtrasProvider
     * @param string $scenario Scenario name
     * @param string $expression Expression to evaluate
     * @param mixed[] $data Fields data
     * @param mixed[] $extras Extra objects to be passed to expression evaluator
     * @param bool $expected Expected result
     * @return void
     */
    public function testEvalExpressionWithExtras(string $scenario, string $expression, array $data, array $extras, bool $expected): void
    {
        $panelName = 'Foobar';
        $config['panels']['Foobar'] = $expression;
        $panel = new Panel($panelName, $config);
        $this->assertEquals($expected, $panel->evalExpression($data, $extras), sprintf('%s - Expression evaluation failed', $scenario));
        unset($panel);
    }

    /**
     * Helper method for checking value in {@link self::testEvalExpressionWithExtras()}
     *
     * @param mixed $foo Value of foo
     * @return bool True if bar
     */
    public function isReallyBar($foo): bool
    {
        return $foo === 'bar';
    }

    /**
     * @return mixed[]
     */
    public function evalExpressionScenariosWithExtrasProvider(): array
    {
        return [
            [
                'Scenario 1 - Call function on custom object',
                "%%hello%% == 'world' && object.isReallyBar(%%foo%%)",
                ['foo' => 'bar', 'hello' => 'world'],
                ['object' => $this],
                true
            ],
        ];
    }
}

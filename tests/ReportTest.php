<?php

use PHPUnit\Framework\TestCase;

class ReportTest extends TestCase
{
    private $script_path = __DIR__ . '/../report.php';
    private $now;

    protected function setUp(): void
    {
        clean_temp_dir();
        if (!is_dir(TASKS_DIR)) {
            mkdir(TASKS_DIR, 0777, true);
        }

        $this->now = new DateTimeImmutable('today');

        // Create test tasks with relative dates
        // Normal task (uncompleted) - always due
        save_xml_file(TASKS_DIR . '/normal.xml', new SimpleXMLElement('<task><name>Normal Report Task</name></task>'));

        // Normal task (completed) - should not be in report
        $completed_normal_xml = new SimpleXMLElement('<task><name>Completed Normal Task</name><history><entry>2025-01-01</entry></history></task>');
        save_xml_file(TASKS_DIR . '/normal_completed.xml', $completed_normal_xml);

        // Due today
        $due_today_date = $this->now->format('Y-m-d');
        save_xml_file(TASKS_DIR . '/due_today.xml', new SimpleXMLElement('<task><name>Due Today Task</name><due>' . $due_today_date . '</due></task>'));

        // Overdue
        $overdue_date = $this->now->modify('-8 days')->format('Y-m-d');
        save_xml_file(TASKS_DIR . '/overdue.xml', new SimpleXMLElement('<task><name>Overdue Task</name><due>' . $overdue_date . '</due></task>'));

        // Upcoming (within preview)
        $upcoming_date = $this->now->modify('+3 days')->format('Y-m-d');
        save_xml_file(TASKS_DIR . '/upcoming.xml', new SimpleXMLElement('<task><name>Upcoming Task</name><due>' . $upcoming_date . '</due><preview>5</preview></task>'));

        // Upcoming (outside preview)
        $future_date = $this->now->modify('+10 days')->format('Y-m-d');
        save_xml_file(TASKS_DIR . '/future.xml', new SimpleXMLElement('<task><name>Future Task</name><due>' . $future_date . '</due><preview>5</preview></task>'));

        // Recurring overdue: completed 19 days ago, recurs every 14 days -> due 5 days ago.
        $rec_overdue_completed = $this->now->modify('-19 days')->format('Y-m-d');
        $xml_rec_over = new SimpleXMLElement('<task><name>Recurring Overdue</name><recurring><completed>' . $rec_overdue_completed . '</completed><duration>14</duration></recurring></task>');
        save_xml_file(TASKS_DIR . '/rec_overdue.xml', $xml_rec_over);

        // Recurring upcoming (no preview, so shouldn't show)
        $rec_upcoming_completed = $this->now->modify('-5 days')->format('Y-m-d');
        $xml_rec_up = new SimpleXMLElement('<task><name>Recurring Upcoming No Preview</name><recurring><completed>' . $rec_upcoming_completed . '</completed><duration>7</duration></recurring></task>');
        save_xml_file(TASKS_DIR . '/rec_upcoming.xml', $xml_rec_up); // Due in 2 days
    }

    private function runReportScript(string $date): string
    {
        global $argv;
        $argv = ['report.php', $date];

        ob_start();
        include $this->script_path;
        return ob_get_clean();
    }

    public function testReportOutputIsDeterministic()
    {
        // Run the report for the mocked "today"
        $output = $this->runReportScript($this->now->format('Y-m-d'));

        // Things that SHOULD be in the report
        $this->assertStringContainsString('Normal Report Task', $output);
        $this->assertStringContainsString('Due Today Task', $output);
        $this->assertStringContainsString('Overdue Task (was due 8 days ago)', $output);
        $this->assertStringContainsString('Upcoming Task (due in 3 days)', $output);
        $this->assertStringContainsString('Recurring Overdue (was due 5 days ago)', $output);

        // Things that should NOT be in the report
        $this->assertStringNotContainsString('Future Task', $output);
        $this->assertStringNotContainsString('Recurring Upcoming No Preview', $output);
        $this->assertStringNotContainsString('Completed Normal Task', $output); // New check
    }

    public function testReportWithPreviewForRecurring()
    {
        // Add a recurring task that will be upcoming with a preview
        // Completed 5 days ago, recurs every 7 days -> due in 2 days. Preview is 5 days.
        $completed_date = $this->now->modify('-5 days')->format('Y-m-d');
        $xml = new SimpleXMLElement('<task><name>Recurring With Preview</name><recurring><completed>' . $completed_date . '</completed><duration>7</duration></recurring><preview>5</preview></task>');
        save_xml_file(TASKS_DIR . '/rec_preview.xml', $xml);

        $output = $this->runReportScript($this->now->format('Y-m-d'));

        $this->assertStringContainsString('Recurring With Preview (due in 2 days)', $output);
    }

    public function testReportWarnsAboutInvalidFiles()
    {
        // Create a malformed XML file
        file_put_contents(TASKS_DIR . '/malformed.xml', '<task><name>Malformed Task</name>');

        // Create an XML file that does not conform to the schema
        file_put_contents(TASKS_DIR . '/non-conforming.xml', '<?xml version="1.0"?><badroot></badroot>');

        $output = $this->runReportScript($this->now->format('Y-m-d'));

        // Check for the warning message
        $this->assertStringContainsString('The following task files are invalid or corrupt and were skipped', $output);

        // Check that the invalid files are listed
        $this->assertStringContainsString('- malformed.xml', $output);
        $this->assertStringContainsString('- non-conforming.xml', $output);

        // Check that the task from the malformed file was not included in the report
        $this->assertStringNotContainsString('Malformed Task', $output);
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * Regenerates the sample import documents in database/samples/.
 *
 * The brief says "commit the sample files you tested with", and binary fixtures
 * committed with no way to reproduce them are a liability — nobody can tell
 * what a .docx contains without opening Word. This command is the source of
 * truth; the committed files are its output.
 *
 * The samples are deliberately imperfect. Real documents are, and a parser
 * that only handles a tidy fixture is worthless.
 */
class MakeSampleImports extends Command
{
    protected $signature = 'import:samples {--path=database/samples}';

    protected $description = 'Generate the sample .docx and .xlsx import files';

    public function handle(): int
    {
        $dir = base_path($this->option('path'));

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->makeDocx("{$dir}/internship-application.docx");
        $this->makeMessyDocx("{$dir}/messy-questionnaire.docx");
        $this->makeDefinitionSheet("{$dir}/field-definitions.xlsx");
        $this->makeHeaderRowSheet("{$dir}/attendee-list.xlsx");

        $this->newLine();
        $this->info('Samples written to '.$dir);

        foreach (glob("{$dir}/*") as $file) {
            $this->line('  '.basename($file).'  ('.number_format(filesize($file) / 1024, 1).' KB)');
        }

        return self::SUCCESS;
    }

    /**
     * A well-structured Word form: real heading styles, numbered questions,
     * checkbox lists.
     */
    private function makeDocx(string $path): void
    {
        $word = new PhpWord;
        $word->addTitleStyle(1, ['bold' => true, 'size' => 18]);
        $word->addTitleStyle(2, ['bold' => true, 'size' => 14]);

        $section = $word->addSection();

        $section->addTitle('Internship Application', 1);
        $section->addText('Please complete every section. Fields marked * are required.');

        $section->addTitle('Personal Details', 2);
        $section->addText('1. Full name *');
        $section->addText('2. What is your email address? *');
        $section->addText('3. Phone number');
        $section->addText('4. Date of birth');

        $section->addTitle('Education History', 2);
        $section->addText('5. Which institution did you attend? *');
        $section->addText('6. Highest qualification');
        $section->addListItem('B.Sc.');
        $section->addListItem('B.Tech.');
        $section->addListItem('BCA');
        $section->addListItem('Other');
        $section->addText('7. Expected graduation date');

        $section->addTitle('Skills and Experience', 2);
        $section->addText('8. Which of these have you worked with? Select all that apply.');
        $section->addListItem('☐ PHP');
        $section->addListItem('☐ Laravel');
        $section->addListItem('☐ MySQL');
        $section->addListItem('☐ JavaScript');
        $section->addListItem('☐ Python');
        $section->addText('9. Describe your most relevant project');
        $section->addText('10. How many years of experience do you have?');

        $section->addTitle('Attachments', 2);
        $section->addText('11. Please attach your resume *');
        $section->addText('12. Rate your confidence with backend development out of 5');

        IOFactory::createWriter($word, 'Word2007')->save($path);

        $this->line('  wrote internship-application.docx');
    }

    /**
     * A deliberately awkward document: no heading styles, a table, inconsistent
     * numbering, an orphan list, and a line that is not a question at all.
     *
     * This is the one that proves the parser is defensive rather than lucky.
     */
    private function makeMessyDocx(string $path): void
    {
        $word = new PhpWord;
        $section = $word->addSection();

        $section->addText('CUSTOMER FEEDBACK');
        $section->addText('We appreciate you taking the time to tell us how we did. '
            .'This survey should take under three minutes and your answers are anonymous '
            .'unless you choose to leave contact details at the end.');

        $section->addText('How would you rate your overall experience?');
        $section->addText('What went well?');
        $section->addText('What could we improve?');

        // An orphan list: options with no question above them.
        $section->addListItem('• Sometimes');

        $section->addText('Would you recommend us?');
        $section->addListItem('Yes');
        $section->addListItem('No');
        $section->addListItem('Maybe');

        // A two-column table, which is how many real forms lay out fields.
        $table = $section->addTable();
        $table->addRow();
        $table->addCell(4000)->addText('Your name');
        $table->addCell(4000)->addText('__________');
        $table->addRow();
        $table->addCell(4000)->addText('Contact email');
        $table->addCell(4000)->addText('__________');

        $section->addText('Anything else you would like to tell us?');

        IOFactory::createWriter($word, 'Word2007')->save($path);

        $this->line('  wrote messy-questionnaire.docx');
    }

    /**
     * Layout A: an explicit field-definition sheet.
     */
    private function makeDefinitionSheet(string $path): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Event Registration');

        $rows = [
            ['Section', 'Label', 'Type', 'Required', 'Options', 'Help'],
            ['Attendee', 'Full name', 'text', 'yes', '', ''],
            ['Attendee', 'Email address', 'email', 'yes', '', 'We will send your ticket here'],
            ['Attendee', 'Mobile number', 'phone', 'no', '', ''],
            ['Attendee', 'T-shirt size', 'dropdown', 'yes', 'XS|S|M|L|XL|XXL', ''],
            ['Sessions', 'Which sessions will you attend?', 'checkbox', 'no', 'Keynote|Workshop A|Workshop B|Panel', 'Select all that apply'],
            ['Sessions', 'Preferred track', 'radio', 'no', 'Backend|Frontend|Design', ''],
            // A deliberately unrecognised type, to prove the fallback works.
            ['Dietary', 'Dietary requirements', 'multiline', 'no', '', ''],
            ['Dietary', 'Any allergies?', 'textarea', 'no', '', ''],
            ['Extras', 'Arrival date', 'date', 'no', '', ''],
            ['Extras', 'Number of guests', 'number', 'no', '', ''],
            // A row with data but no label, to prove it is reported not crashed.
            ['Extras', '', 'text', 'no', '', 'orphaned row'],
        ];

        foreach ($rows as $i => $row) {
            $sheet->fromArray($row, null, 'A'.($i + 1));
        }

        (new XlsxWriter($book))->save($path);

        $this->line('  wrote field-definitions.xlsx');
    }

    /**
     * Layout B: a plain data sheet, header row plus rows of real values.
     */
    private function makeHeaderRowSheet(string $path): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Attendees');

        $rows = [
            ['Full Name', 'Contact', 'Joined On', 'Department', 'Years', 'Notes'],
            ['Priya Sharma', 'priya@example.com', '2024-06-01', 'Engineering', '4', 'Prefers morning sessions'],
            ['Arjun Mehta', 'arjun@example.com', '2023-11-14', 'Design', '7', ''],
            ['Fatima Khan', 'fatima@example.com', '2025-02-20', 'Engineering', '2', 'Vegetarian'],
            ['Rohan Das', 'rohan@example.com', '2024-09-09', 'Sales', '9', ''],
            ['Meera Iyer', 'meera@example.com', '2025-01-05', 'Design', '3', ''],
        ];

        foreach ($rows as $i => $row) {
            $sheet->fromArray($row, null, 'A'.($i + 1));
        }

        (new XlsxWriter($book))->save($path);

        $this->line('  wrote attendee-list.xlsx');
    }
}

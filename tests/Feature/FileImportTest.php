<?php

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\UserTypeEnum;
use App\Models\ActivityLog;
use App\Models\Tag;
use App\Services\SpreadsheetService;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * SpreadsheetService and WithFileImport, exercised through the tags listing — the
 * first screen to carry an import.
 */
beforeEach(function () {
    $this->admin = userOfType(UserTypeEnum::ADMIN);
});

/**
 * A CSV as an upload. UploadedFile::fake() rather than a plain UploadedFile, because
 * Livewire's test harness reads the ->name property only the fake carries.
 */
function csvUpload(string $contents, string $name = 'tags.csv'): File
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

/**
 * The same again as a real workbook, so the XLSX path is exercised rather than
 * assumed to behave like the CSV one.
 *
 * @param  array<int, array<int, string>>  $rows
 */
function xlsxUpload(array $rows, string $name = 'tags.xlsx'): File
{
    $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';

    $writer = new XlsxWriter;
    $writer->openToFile($path);

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return UploadedFile::fake()->createWithContent($name, file_get_contents($path));
}

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// THE READER

test('a csv comes back keyed by its header row', function () {
    $file = csvUpload("Name,Notes\nwinter,cold\nskincare,\n");

    $rows = app(SpreadsheetService::class)->rows($file->getRealPath(), 'csv');

    expect($rows)->toHaveCount(2)
        // "Name" is snake-cased so it lands on the column it came from.
        ->and($rows[0])->toBe(['name' => 'winter', 'notes' => 'cold'])
        // A row short of the header is padded rather than dropped.
        ->and($rows[1])->toBe(['name' => 'skincare', 'notes' => '']);
});

test('an xlsx reads back the same as the csv did', function () {
    $file = xlsxUpload([['Name', 'Notes'], ['winter', 'cold'], ['skincare', '']]);

    $rows = app(SpreadsheetService::class)->rows($file->getRealPath(), 'xlsx');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['name'])->toBe('winter')
        ->and($rows[1]['name'])->toBe('skincare');
});

test('a blank line in the middle of a file is not a row', function () {
    $file = csvUpload("name\nwinter\n\nskincare\n");

    expect(app(SpreadsheetService::class)->rows($file->getRealPath(), 'csv'))->toHaveCount(2);
});

test('the reader stops at the limit it was given', function () {
    $file = csvUpload("name\nwinter\nskincare\nroutine\n");

    expect(app(SpreadsheetService::class)->rows($file->getRealPath(), 'csv', 2))->toHaveCount(2);
});

test('a format the reader does not handle is refused by name', function () {
    app(SpreadsheetService::class)->rows('whatever.pdf', 'pdf');
})->throws(InvalidArgumentException::class, 'Unsupported spreadsheet format: pdf');

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// THE SCREEN

test('an imported file creates the rows it carries', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.content.tags')
        ->set('importFile', csvUpload("name\nwinter\nskincare\n"))
        ->call('import')
        ->assertHasNoErrors();

    expect(Tag::query()->orderBy('id')->pluck('name')->all())->toBe(['winter', 'skincare']);
});

test('a name already on the list is left alone rather than duplicated', function () {
    Tag::query()->create(['name' => 'winter', 'slug' => 'winter']);

    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.content.tags')
        ->set('importFile', csvUpload("name\nWinter\nskincare\n"))
        ->call('import');

    expect(Tag::query()->count())->toBe(2)
        // Only the new one counts as imported; the repeat did nothing.
        ->and($component->get('importedCount'))->toBe(1);
});

test('a row that cannot be used is named and the rest still land', function () {
    // A row with something in it but nothing in the column that matters. A line that
    // is blank all the way across never reaches the page — the reader drops it as a
    // typing artefact rather than passing on a record of empty strings.
    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.content.tags')
        ->set('importFile', csvUpload("name,notes\nwinter,cold\n,orphaned\nskincare,warm\n"))
        ->call('import');

    expect($component->get('importedCount'))->toBe(2)
        ->and($component->get('importSkipped'))->toHaveCount(1)
        // The line number counts the header, so the bad row is line 3 of the file.
        ->and($component->get('importSkipped')[0])->toContain('Line 3')
        ->and($component->get('importSkipped')[0])->toContain('blank');
});

test('a line that is blank all the way across is skipped silently', function () {
    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.content.tags')
        ->set('importFile', csvUpload("name\nwinter\n \nskincare\n"))
        ->call('import');

    expect($component->get('importedCount'))->toBe(2)
        ->and($component->get('importSkipped'))->toBeEmpty();
});

test('a file missing a column it needs is refused before anything is written', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.content.tags')
        ->set('importFile', csvUpload("label\nwinter\n"))
        ->call('import')
        ->assertHasErrors();

    expect(Tag::query()->count())->toBe(0);
});

test('a file of the wrong kind never reaches the reader', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.content.tags')
        ->set('importFile', UploadedFile::fake()->create('tags.pdf', 10, 'application/pdf'))
        ->call('import')
        ->assertHasErrors('importFile');

    expect(Tag::query()->count())->toBe(0);
});

test('the run is written to the audit trail with what it took and what it left', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.content.tags')
        ->set('importFile', csvUpload("name,notes\nwinter,cold\n,orphaned\n"))
        ->call('import');

    $log = ActivityLog::query()->latest('id')->first();

    expect($log->activity_log_action)->toBe(ActivityActionEnum::IMPORT)
        ->and($log->description)->toBe('Imported 1 tags (1 skipped)');
});

test('an account below create cannot import', function () {
    $restricted = adminWithRoles(roleWithGates('Reader', ['content.tags' => GateAccessEnum::MODIFY->value]));

    Livewire::actingAs($restricted)
        ->test('pages::admin.content.tags')
        ->set('importFile', csvUpload("name\nwinter\n"))
        ->call('import')
        ->assertHasErrors();

    expect(Tag::query()->count())->toBe(0);
});

<?php

require_once 'common.php';

// --- Helper Functions ---

if (!function_exists('display_current_details')) {
    /**
     * Displays the current details of the loaded task.
     * @param SimpleXMLElement $xml The task's XML object.
     */
    function display_current_details(SimpleXMLElement $xml): void
    {
        echo "\n--- Current Task Details ---\n";
        echo "Name: " . $xml->name . "\n";
        $type = get_task_type($xml);
        echo "Type: " . ucfirst($type) . "\n";

        if ($type === 'scheduled') {
            // This check ensures a due date is present, which might not be the case
            // for a legacy task that hasn't been completed yet.
            if (isset($xml->due)) {
                echo "Due Date: " . $xml->due . "\n";
            }
            if (isset($xml->reschedule)) {
                echo "Reschedules: Every " . $xml->reschedule->interval . "\n";
                echo "Reschedule from: " . ucfirst(str_replace('_', ' ', $xml->reschedule->from)) . "\n";
            }
        }

        if (isset($xml->preview)) {
            echo "Preview: " . $xml->preview . " days in advance\n";
        }
        echo "---------------------------\n";
    }
}

if (!function_exists('process_edit_choice')) {
    /**
     * Calls the correct function to handle the user's edit choice.
     * @param SimpleXMLElement $xml The XML object to modify.
     * @param string $choice The user's chosen action.
     * @return string The potentially new task type.
     */
    function process_edit_choice(SimpleXMLElement $xml, string $choice): string
    {
        $currentType = get_task_type($xml);

        switch ($choice) {
            case 'n': // Name
                $newName = '';
                while (empty(trim($newName))) {
                    $newName = prompt_user("Enter the new task name: ");
                    if (empty(trim($newName))) {
                        echo "Name cannot be empty.\n";
                    }
                }
                $xml->name = htmlspecialchars($newName);
                break;

            case 't': // Change Task Type
                if ($currentType === 'normal') {
                    echo "Converting to a Scheduled task.\n";
                    collect_scheduled_task_details($xml);
                } else { // Is scheduled
                    if (get_yes_no_input("Convert to a Normal task? This will remove due date and reschedule settings. (y/N): ", 'n')) {
                        unset($xml->due);
                        if (isset($xml->reschedule)) {
                            unset($xml->reschedule);
                        }
                        if (isset($xml->preview)) {
                            unset($xml->preview);
                        }
                    }
                }
                break;

            case 'd': // Edit Due Date
                $xml->due = get_validated_date_input("Enter new due date (YYYY-MM-DD): ");
                break;

            case 'p': // Edit Preview
                $preview_days = get_optional_positive_integer_input("Enter new preview days (or press Enter to remove): ");
                if ($preview_days === null) {
                    if (isset($xml->preview)) {
                        unset($xml->preview);
                        echo "Preview setting removed.\n";
                    }
                } else {
                    $xml->preview = $preview_days;
                    echo "Preview set to $preview_days days.\n";
                }
                break;

            case 'r': // Edit Reschedule settings
                if (isset($xml->reschedule)) {
                    if (get_yes_no_input("Do you want to remove the existing reschedule settings? (y/N): ", 'n')) {
                        unset($xml->reschedule);
                        echo "Reschedule settings have been removed.\n";
                        break;
                    }
                }

                echo "Editing reschedule settings...\n";
                get_reschedule_input($xml);
                break;
        }
        return get_task_type($xml);
    }
}

// --- Main Script Execution ---

echo "--- Edit an Existing Task ---\n";
$filepath = select_task_file($argv, 'edit', 'active');
if ($filepath === null) {
    exit(0);
}

$xml = simplexml_load_file($filepath);
$original_name = (string)$xml->name;

// --- Automatic Migration from Legacy Format ---
if (migrate_legacy_task_if_needed($xml)) {
    echo "Notice: This task used a legacy format and has been automatically updated.\n";
}


while (true) {
    display_current_details($xml);
    $type = get_task_type($xml);

    $menu_options = [
        'n' => 'Edit Name',
        't' => 'Change Task Type',
    ];
    if ($type === 'scheduled') {
        if (isset($xml->due)) {
            $menu_options['d'] = 'Edit Due Date';
        }
        $menu_options['r'] = 'Edit/Add Reschedule Settings';
        $menu_options['p'] = 'Edit Preview Days';
    }
    $menu_options['s'] = 'Save and Exit';

    $choice = get_menu_choice("What would you like to edit?", $menu_options);
    if ($choice === 's') {
        break;
    }

    process_edit_choice($xml, $choice);

    $new_name = (string)$xml->name;
    if ($new_name !== $original_name) {
        if (get_yes_no_input("Task name has changed. Rename the file on disk? (Y/n): ", 'y')) {
            $base_filename = sanitize_filename($new_name);
            $new_filepath = TASKS_DIR . '/' . $base_filename . '.xml';
            $counter = 1;
            while (file_exists($new_filepath) && realpath($new_filepath) !== realpath($filepath)) {
                $new_filepath = TASKS_DIR . '/' . $base_filename . '_' . $counter . '.xml';
                $counter++;
            }
            if (rename($filepath, $new_filepath)) {
                echo "File successfully renamed to '" . basename($new_filepath) . "'.\n";
                $filepath = $new_filepath;
                $original_name = $new_name;
            } else {
                echo "Error: Could not rename the file. Reverting name change.\n";
                $xml->name = htmlspecialchars($original_name);
            }
        } else {
            $original_name = $new_name;
        }
    }
}

if (save_xml_file($filepath, $xml)) {
    echo "\nSuccess! Task file updated at: $filepath\n";
} else {
    echo "\nError! Could not save the updated task file.\n";
}

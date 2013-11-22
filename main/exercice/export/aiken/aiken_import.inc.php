<?php
/**
 * @copyright (c) 2001-2006 Universite catholique de Louvain (UCL)
 *
 * @license http://www.gnu.org/copyleft/gpl.html (GPL) GENERAL PUBLIC LICENSE
 *
 * @package chamilo.exercise
 *
 * @author claro team <cvs@claroline.net>
 * @author Guillaume Lederer <guillaume@claroline.net>
 * @author César Perales <cesar.perales@gmail.com> Parse function for Aiken format
 */
/**
 * Security check
 */
if (count(get_included_files()) == 1)
	die('---');

/**
 * function to create a temporary directory (SAME AS IN MODULE ADMIN)
 */

function tempdir($dir, $prefix = 'tmp', $mode = 0777) {
	if (substr($dir, -1) != '/')
		$dir .= '/';

	do {
		$path = $dir . $prefix . mt_rand(0, 9999999);
	} while (!mkdir($path, $mode));

	return $path;
}

/**
 * @return the path of the temporary directory where the exercise was uploaded and unzipped
 */

function get_and_unzip_uploaded_exercise($baseWorkDir, $uploadPath) {
	global $_course, $_user;
	//Check if the file is valid (not to big and exists)
	if (!isset ($_FILES['userFile']) || !is_uploaded_file($_FILES['userFile']['tmp_name'])) {
		// upload failed
		return false;
	}	
	if (preg_match('/.zip$/i', $_FILES['userFile']['name']) && handle_uploaded_document($_course, $_FILES['userFile'], $baseWorkDir, $uploadPath, $_user['user_id'], 0, null, 1)) {
		if (!function_exists('gzopen')) {			
			//claro_delete_file($uploadPath);
			return false;
		}
		// upload successfull
		return true;
	} else {
		//claro_delete_file($uploadPath);
		return false;
	}
}
/**
 * main function to import an exercise,
 *
 * @return an array as a backlog of what was really imported, and error or debug messages to display
 */

function import_exercise($file) {
	global $exercise_info;
	global $element_pile;
	global $non_HTML_tag_to_avoid;
	global $record_item_body;
	// used to specify the question directory where files could be found in relation in any question
	global $questionTempDir;

	$archive_path = api_get_path(SYS_ARCHIVE_PATH) . 'aiken';
	$baseWorkDir = $archive_path;

	if (!is_dir($baseWorkDir)) {
		mkdir($baseWorkDir, api_get_permissions_for_new_directories(), true);
	}

	$uploadPath = '/';

	// set some default values for the new exercise
	$exercise_info = array ();
	$exercise_info['name'] = preg_replace('/.zip$/i', '', $file);
	$exercise_info['question'] = array();
	$element_pile = array ();

	// create parser and array to retrieve info from manifest
	$element_pile = array (); //pile to known the depth in which we are
	//$module_info = array (); //array to store the info we need

	// if file is not a .zip, then we cancel all
	if (!preg_match('/.zip$/i', $file)) {
		Display :: display_error_message(get_lang('You must upload a zip file'));
		return false;
	}

	// unzip the uploaded file in a tmp directory
	if (!get_and_unzip_uploaded_exercise($baseWorkDir, $uploadPath)) {
		Display :: display_error_message(get_lang('You must upload a zip file'));
		return false;
	}

	// find the different manifests for each question and parse them.
	$exerciseHandle = opendir($baseWorkDir);
	//$question_number = 0;
	$file_found = false;
	$operation = false;
    $result = false;
	// parse every subdirectory to search txt question files
	while (false !== ($file = readdir($exerciseHandle))) {
		if (is_dir($baseWorkDir . '/' . $file) && $file != "." && $file != "..") {
			//find each manifest for each question repository found
			$questionHandle = opendir($baseWorkDir . '/' . $file);
			while (false !== ($questionFile = readdir($questionHandle))) {
				if (preg_match('/.txt$/i', $questionFile)) {
					$result = parse_file($baseWorkDir, $file, $questionFile);
					$file_found = true;
				}
			}
		} elseif (preg_match('/.txt$/i', $file)) {
			$result = parse_file($baseWorkDir, '', $file);
			$file_found = true;
		} // else ignore file
	}
	if (!$file_found) {
		Display :: display_error_message(get_lang('No TXT file found in the zip'));        
		return false;
	}
    if ($result == false ) {        
        return false;
    }

	//add exercise in tool

	//1.create exercise
	$exercise = new Exercise();
	$exercise->exercise = $exercise_info['name'];
    
	$exercise->save();
	$last_exercise_id = $exercise->selectId();
	if (!empty($last_exercise_id)) {
		//For each question found...
		foreach ($exercise_info['question'] as $key => $question_array) {
			//2.create question
			$question = new Aiken2Question();
			$question->type = $question_array['type'];
			$question->setAnswer();
			$question->updateTitle($question_array['title']); // question ...
			$type = $question->selectType();
			$question->type = constant($type); // type ...
			$question->save($last_exercise_id); // save computed grade
			$last_question_id = $question->selectId();
			//3.create answer
			$answer = new Answer($last_question_id);
			$answer->new_nbrAnswers = count($question_array['answer']);
			foreach ($question_array['answer'] as $key => $answers) {
				$key++; 				
				$answer->new_answer[$key] = $answers['value']; // answer ...
				$answer->new_comment[$key] = $answers['feedback']; // comment ...
				$answer->new_position[$key] = $key; // position ...
				// correct answers ...
				if (in_array($key, $question_array['correct_answers'])) {
					$answer->new_correct[$key] = 1;
				} else {
					$answer->new_correct[$key] = 0;
				}
				error_log($question_array['weighting']);
				$answer->new_weighting[$key] = $question_array['weighting'][$key - 1];
			}
			$answer->save();
		}
		// delete the temp dir where the exercise was unzipped
		my_delete($baseWorkDir . $uploadPath);
		$operation = true;
	}
	return $operation;
}

function parse_file($exercisePath, $file, $questionFile) {
	global $exercise_info;
	global $questionTempDir;

	$questionTempDir = $exercisePath . '/' . $file . '/';
	$questionFilePath = $questionTempDir . $questionFile;


	$data = file($questionFilePath);

	$question_index = 0;
	$correct_answer = '';
	$answers_array = array();
	foreach ($data as $linea => $info) {
		$exercise_info['question'][$question_index]['type'] = 'MCUA';
		if (preg_match('/^([A-Z])(\)|\.)\s(.*)/', $info, $matches)) {
			//adding one of the posible answers
			$exercise_info['question'][$question_index]['answer'][]['value'] = $matches[3];
			$answers_array[] = $matches[1];
		} elseif (preg_match('/^ANSWER:\s?([A-Z])\s?/', $info, $matches)) {
			//the correct answers
			$correct_answer_index = array_search($matches[1], $answers_array);
			$exercise_info['question'][$question_index]['correct_answers'][] = $correct_answer_index + 1;
			//weight for correct answer
			$exercise_info['question'][$question_index]['weighting'][$correct_answer_index] = 1;
		} elseif (preg_match('/^ANSWER_EXPLANATION:\s?(.*)\s?/', $info, $matches)) {  
			//Comment of correct answer
			$exercise_info['question'][$question_index]['answer'][$correct_answer_index]['feedback'] = $matches[1];
		} elseif (preg_match('/^TAGS:\s?([A-Z])\s?/', $info, $matches)) { 
		 	//TAGS for chamilo >= 1.10
			$exercise_info['question'][$question_index]['answer_tags'] = explode(',', $matches[1]);
		} elseif (preg_match('/^\n/',$info)) {
			//moving to next question
			$question_index++;
			//emptying answers array when moving to next question
			$answers_array = array();
		} else {
			//Question itself
			$exercise_info['question'][$question_index]['title'] = $info;
		}
	}
	return true;
}
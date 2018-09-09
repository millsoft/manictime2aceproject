<?php

/**
 * manictime2aceproject Importer
 * (c) 2018 by Michael Milawski
 */

require_once(__DIR__ . "/vendor/autoload.php");
use Millsoft\AceProject\AceProject;
use Millsoft\AceProject\Project;
use Millsoft\AceProject\Task;
use Millsoft\AceProject\Timesheet;
use Millsoft\AceProject\Time;


class Importer{

	//TODO: Add your csv file here:
	public $import_file = __DIR__ . "/data/ManicTime_TimeSheet_2018-09-07.csv";

	public $useAceproject = true;

	public $config = null;

	public $prepare_file = __DIR__ . "/data/prepare.json";
	public $projects_file = __DIR__ . "/data/projects.json";

	public $projects = null;
	private $timeTypes = null;

	public function __construct(){
		$this->loadConfig();
	}

	public function loadConfig(){
		$config_file = __DIR__ . "/config.json";
		if(!file_exists($config_file)){
			die("config.json not found.");
		}

		$this->config = json_decode(file_get_contents($config_file));
	}

	public function generatePrepareFile(){
		//At first import the csv file
		$data = $this->loadCsvFile();
		$json = json_encode($data, JSON_PRETTY_PRINT);

		$json_error = json_last_error();
		if($json_error > 0){
			die("JSON Fehler: " . $json_error);
		}

		file_put_contents( $this->prepare_file, $json);

	}
	public function loadCsvFile(){
		$data = new ParseCsv\Csv($this->import_file);

		//Generate a json file:
		$output = [];
		foreach($data->data as $item){
			$hash = md5(
				$item['Notizen'].$item['tag 1'].$item['tag 2']
			);
			$output["items"][$hash]['aceproject'] = [
				"task_id" => -1,
				"task_name" => "",
				"task_project" => "",
			];
			$output["items"][$hash]['data'] = $item;

		}

		return $output;
	}


	//Login to aceproject
	public function aceLogin(){
		$re = AceProject::login(
			$this->config->aceproject_username,
			$this->config->aceproject_password,
			$this->config->aceproject_subdomain
		);
		$errors = AceProject::getLastError();
		if(!empty($errors)){
			print_r($errors);
			die();			
		}
	}

	public function aceGetProjects(){

		if($this->projects !== null){
			return $this->projects;
		}

		if(file_exists($this->projects_file)){
			$this->projects = json_decode(file_get_contents($this->projects_file), true);
			return $this->projects;
		}

		$this->aceLogin();
		$projects = Project::GetProjects();
		$errors = AceProject::getLastError();

		if (!empty($errors)) {
		    //some error occured:
		    print_r($errors);
		    die();
		}

		$project_data = [];

		foreach ($projects as $project) {
		    //echo $project->PROJECT_ID . "\t" . $project->PROJECT_NAME . "\n";
		    $project_data[$project->PROJECT_ID]  = $project->PROJECT_NAME;
		}

		file_put_contents($this->projects_file, json_encode($project_data, JSON_PRETTY_PRINT));
		$this->projects = $project_data;
		return $project_data;
	}

	/**
	 * Get aceproject project ID by partial name of task name
	 * @param  string $searchString
	 * @return int - project id
	 */
	public function getProjectId($searchString){
		if(is_numeric($searchString)){
			return $searchString;
		}

		$projects = $this->aceGetProjects();

		$pro = array_filter($projects, function($el) use ($searchString) {
        	return ( stripos($el, $searchString) !== false );
    	});

		if(empty($pro)){
			echo "Warning: no project found for search '$searchString'\n";
			return null;
		}

		if(count($pro) > 1){
			echo "Warning: more than one projects found for search '$searchString'\n";
			return null;
		}

		$keys = array_keys($pro);
		return $keys[0];

	}

	public function loadAceprojectData(){
		$this->aceLogin();
		$this->timeTypes = Time::GetTimeTypes();


	}

	public function importPrepFile(){
		$items = json_decode(file_get_contents($this->prepare_file), true);
		$projects = $this->aceGetProjects();


		foreach($items['items'] as $hash => $item){

			if($item['aceproject']['task_id'] == -1){
				continue;
			}

			$this->importTask($item, $hash);

		}

	}



	public function importTask($taskData, $hash = ''){
			echo "Importing $hash\n";

			$aceproject = $taskData['aceproject'];
			//Do we have task id?
			$task_id = $aceproject['task_id'];



			if($task_id === 0){
				//No task, we should add the task first:
				if($this->useAceproject){
					$task_id = $this->addTask($aceproject['task_name'], $aceproject['task_project']);
				}else{
					$task_id = 1;
				}
			}

			//Now split whole month in weeks:
			$weekData = $this->getWeekData($taskData['data']);


			foreach($weekData as $wd){
				if($wd['worked_hours'] == 0){
					//Do nothing when all days are set to 0.00
					continue;
				}

				$dayValues = $wd['day_values'];

				//Now add the workitem to database:

				echo ("Importing {$wd['week_start']} - {$wd['week_end']}\n");
				flush();

				$params = [
					'weekstart' => $wd['week_start'],
					'taskid' => $task_id,
					//'timetypeid' => $this->timeTypes->TIME_TYPE_ID,
					'timetypeid' => 1,
					'hoursday1' => $dayValues[0],
					'hoursday2' => $dayValues[1],
					'hoursday3' => $dayValues[2],
					'hoursday4' => $dayValues[3],
					'hoursday5' => $dayValues[4],
					'hoursday6' => $dayValues[5],
					'hoursday7' => $dayValues[6],
				];

				print_r($params);


				//Add time:
				if($this->useAceproject){

					//Check if there is already a sheet available that we can use:
					$timeSheetExists = $this->timeSheetExists($task_id, $wd['week_start'], $dayValues);

					if($timeSheetExists === false){
						$re = TimeSheet::SaveWorkItem($params);
						$this->checkAceErrors();
					}else{
						echo "Skipping adding of timesheet because timesheet already exists for {$wd['week_start']}\n";
					}

				}else{
					echo "Skipping Aceproject add...\n";
				}

			}

	}


	/**
	 * Check if the timesheet exists
	 * @param  int $task_id 
	 * @param  string $week_start beginning of the week (YYYY-MM-DD)
	 * @param  array $times      array with the week work hours
	 * @return bool
	 */
	public function timeSheetExists($task_id, $week_start, $times){

		//Get all work items for the week start:
		$workItems = TimeSheet::GetMyWorkItems([
			"timesheetdatefrom" => $week_start,
		]);

		if(empty($workItems)){
			return false;
		}

		//Find the item with the task_id
		foreach($workItems as $workItem){
			if($workItem->TASK_ID == $task_id){
				//Task found, check if the times are equal:
				if(
					$workItem->TOTAL1 == $times[0] && 
					$workItem->TOTAL2 == $times[1] && 
					$workItem->TOTAL3 == $times[2] && 
					$workItem->TOTAL4 == $times[3] && 
					$workItem->TOTAL5 == $times[4] && 
					$workItem->TOTAL6 == $times[5] && 
					$workItem->TOTAL7 == $times[6]
				){
					return true;
				}
			}
		}


		//Nothing found
		return false;

	}

	public function getWeekData($data){

		$weekData = [];
		$days = [];

		foreach($data as $date => $hours){
			$timestamp = strtotime($date);
			if($timestamp !== false){
				$date_formatted = date("Y-m-d", $timestamp);
				$days[$date_formatted] = $hours;
			}
		}


		//Get whole month divided by weeks:
		foreach($days as $day => $val){

			if(date("D", strtotime($day)) == 'Mon'){
				$monday = $day;
			}else{
				$monday = date("Y-m-d", strtotime($day . ' last Monday'));
			}

			if(date("D", strtotime($day)) == 'Sun'){
				$sunday = $day;
			}else{
				$sunday = date("Y-m-d", strtotime($day . ' next Sunday'));
			}

			$hash = md5($monday . $sunday);
			
			$weekData[$hash]["week_start"] = $monday;
			$weekData[$hash]["week_end"] = $sunday;

			$weekData[$hash]['days'][$day] = $val;

		}

		//fill missing days when on month start / end
		//foreach($weekData as $);
		foreach($weekData as &$d){
			if(count($d['days']) != 7){
				//get first day of array:
				$days = array_keys($d['days']);
				$firstDay = $days[0];
				$lastDay = $days[count($days)-1];


				if(date("D", strtotime($firstDay)) !== "Mon"){
					//terate backwards until monday reached:
					$currentDay = $firstDay;
					while (date("D", strtotime($currentDay)) !== "Mon") {
						$ts_currentDay = strtotime('-1 day', strtotime($currentDay) );
						$currentDay = date("Y-m-d", $ts_currentDay);
						$d['days'] = [$currentDay => 0] + $d['days'];
					}
				}

				if(date("D", strtotime($lastDay)) !== "Sun"){
					//terate backwards until monday reached:
					$currentDay = $lastDay;
					while (date("D", strtotime($currentDay)) !== "Sun") {
						$ts_currentDay = strtotime('+1 day', strtotime($currentDay) );
						$currentDay = date("Y-m-d", $ts_currentDay);
						$d['days'] = $d['days'] + [$currentDay => 0];
					}
				}
			}

			//Now convert the dates to index:
			$d['day_values'] = array_values($d['days']);

			//Count the working hours for each week:
			$d['worked_hours'] = 0;

			//Now convert 0,00 to 0.00
			foreach($d['day_values'] as &$val){
				$val = str_replace(',', '.', $val);
				$d['worked_hours'] += (double) $val;
			}

			
		}


		return $weekData;

	}

	public function addTask($task_name, $task_project){
		$project_id = $this->getProjectId($task_project);

		if($project_id === null){
			echo("Warning: Could not recognize project\n");
			return false;
		}


		//Check if the task exists
		$task_exists = $this->taskExists($task_name, $project_id);

		if(!$task_exists){
			//add task:
			
			$task = Task::SaveTask([
				"projectid" => $project_id,
				"summary" => $task_name,
				"notify" => false

			]);

			$task_id = $task->TASK_ID;

		}else{
			echo "Warning: Task already exists!\n";

			if(count($task_exists) > 1){
				echo "Warning: more tasks were found for this search term\n";
			}
			$task_id = $task_exists[0]->TASK_ID;
		}




		return $task_id;

	}

	//Check if a task exists
	public function taskExists($task_name, $project_id){
		$this->aceLogin();

		$task = Task::GetTasks([
			"projectid" => $project_id,
			"texttosearch" => $task_name,
			"forcombo" => true
		]);

		$this->checkAceErrors();

		if(empty($task)){
			return false;
		}else{
			return $task;
		}

	}

	//Check aceproject api error
	private function checkAceErrors($die = true){
		$errors = AceProject::getLastError();
		if(!empty($errors)){
			print_r($errors);
			if($die){
				die();
			}
		}
	}

	public function loadWorkItems(){
		$this->aceLogin();

		$weeks = TimeSheet::GetMyWorkItems([
			"timesheetdatefrom" => "2018-08-06",
			//"timesheetdateto" => "2018-09-05",
		]);

		$this->checkAceErrors();

		print_r($weeks);
		die();



	}

	public function test(){
		$this->aceLogin();
		//$weeks = TimeSheet::GetMyWeeks();
		$weeks = TimeSheet::GetMyWeeks();

		print_r($weeks);

	}


}

$i = new Importer();

//Step 0:
//Generate a prepare.json file, here we should connect the aceproject tasks
//$i->generatePrepareFile();

//Step 1:
//Connect with aceproject
//$u->loadAceprojectData();

//Step 2:
//Import the modified prepare.json
$i->importPrepFile();

//$i->loadWorkItems();

//$i->test();



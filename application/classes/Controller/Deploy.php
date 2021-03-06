<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Deploy extends Controller_Gui {
	
	private $repositories = array(
		'libs-release-local',
		'libs-snapshot-local',
		'plugins-release-local',
		'plugins-snapshot-local',
	);
	
	public function before()
	{
		parent::before();
		
		$this->page_title = 'Deploy';
		$this->current_page = 'deploy';
	}

	public function action_index()
	{
		$this->script = '$( document ).ready(function() { $(\'input[type=file]\').bootstrapFileInput(); $(\'.file-inputs\').bootstrapFileInput(); });';
		$this->scripts = array('bootstrap.file-input.js');
		
		$this->template->content = View::factory('gui/deploy/main')->set('succes', Session::instance()->get_once('last_artifact_added'));
	}
	
	public function action_upload()
	{
		$array = Validation::factory($_FILES);
		$array->rule('artifact', 'Upload::not_empty');
		$array->rule('artifact', 'Upload::size', array(':value', '8M'));
		$array->rule('artifact', 'Upload::type', array(':value', array('jar')));
		$array->rule('artifact', 'Upload::valid');
		
		if ($array->check())
		{
			// Upload is valid, save it
    		$saved_file = Upload::save($array['artifact']);
			
			Session::instance()->set('artifact_file', $saved_file);
			Session::instance()->set('artifact_file_name', $_FILES['artifact']['name']);
			
			$this->redirect(Route::get('default') -> uri(array('controller' => 'deploy', 'action' => 'pom')));
		} else {
			$this->script = '$( document ).ready(function() { $(\'input[type=file]\').bootstrapFileInput(); $(\'.file-inputs\').bootstrapFileInput(); });';
			$this->scripts = array('bootstrap.file-input.js');
			
			$errors = $array->errors('artifact');
			
			$this->template->content = View::factory('gui/deploy/main')->bind('errors', $errors);
		}
	}
	
	public function action_pom()
	{
		$saved_file = Session::instance()->get('artifact_file');
		if (!$saved_file || !file_exists($saved_file))
		{
			$this->redirect(Route::get('default') -> uri(array('controller' => 'deploy', 'action' => 'index')));
		}
		
		if ($this->request->method() == 'POST')
		{
			$settings = $_POST;
			$validation = Validation::factory($_POST);
			$validation->rule('groupId', 'not_empty');
			$validation->rule('artifactId', 'not_empty');
			$validation->rule('version', 'not_empty');
			$validation->rule('type', 'not_empty');
			$validation->rule('type', 
				function(Validation $array, $field, $value)
				{
					if (!in_array(strtolower($value), array('jar', 'war')))
	 				{
						$array->error($field, 'Not Jar or War');
					}
				}
				, array(':validation', ':field', ':value')
			);
			
			if ($validation->check())
			{
				// TODO: save artifact
				$path = REPOPATH . $settings['repository'] . '/' . str_replace('.', '/', $settings['groupId']) . '/' . $settings['artifactId'] . '/' . $settings['version'];
				if (!file_exists($path))
				{
					mkdir($path, 0777, TRUE);
				}
				
				if (strlen($settings['classifier']) > 0) 
				{
					$artifact_file = $path . '/' . $settings['artifactId'] . '-' . $settings['version'] . '-' . $settings['classifier'] . '.' . $settings['type'];
					
					rename($saved_file, $artifact_file);
				
					file_put_contents($artifact_file . '.sha1', sha1_file($artifact_file));
					file_put_contents($artifact_file . '.md5', md5_file($artifact_file));
				} else {
					$artifact_file = $path . '/' . $settings['artifactId'] . '-' . $settings['version'] . '.' . $settings['type'];
					$pom_file = $path . '/' . $settings['artifactId'] . '-' . $settings['version'] . '.pom';
				
					rename($saved_file, $artifact_file);
					file_put_contents($pom_file, $settings['pom']);
				
					file_put_contents($artifact_file . '.sha1', sha1_file($artifact_file));
					file_put_contents($pom_file . '.sha1', sha1_file($pom_file));
					file_put_contents($artifact_file . '.md5', md5_file($artifact_file));
					file_put_contents($pom_file . '.md5', md5_file($pom_file));
				}
				
				Session::instance()->set('last_artifact_added', $settings['artifactId']);
				Session::instance()->delete('artifact_file');
				Session::instance()->delete('artifact_file_name');
				
				$this->redirect(Route::get('default') -> uri(array('controller' => 'deploy', 'action' => 'index')));
			} else {
				$errors = $validation->errors();
				
				$this->template->content = View::factory('gui/deploy/pom')->bind('settings', $settings)->bind('errors', $errors)->set('repositories', $this->repositories);
			}
		} else {
			$settings = $this->get_artifact_data_from_jar($saved_file);
			$settings['repository'] = 'libs-release-local';
			$this->template->content = View::factory('gui/deploy/pom')->bind('settings', $settings)->set('repositories', $this->repositories);
		}
	}
	
	function get_artifact_data_from_jar($jarfile)
	{
		$zip = zip_open($jarfile);
		
		do {
			$zip_entry = zip_read($zip);
		} while ($zip_entry && !$this->endsWith(zip_entry_name($zip_entry), 'pom.xml'));
		
		if (! $zip_entry)
		{
			$settings = array();
			$artifact_file_name = Session::instance()->get('artifact_file_name');
			$split_artifact_file_name =  preg_split('/-/', $artifact_file_name);
			$settings['pom'] = '';
			$settings['groupId'] = $split_artifact_file_name[0];
			$settings['artifactId'] = $split_artifact_file_name[0];
			$settings['version'] = $split_artifact_file_name[1];
			$split_artifact_file_name = preg_split('/[.]/', $split_artifact_file_name[2]);
			if (count($split_artifact_file_name) == 2)
			{
				$settings['classifier'] = $split_artifact_file_name[0];
				$settings['type'] = $split_artifact_file_name[1];
			} else {
				$settings['classifier'] = '';
				$settings['type'] = $split_artifact_file_name[0];
			}
			return $settings;
		} else {
			zip_entry_open($zip, $zip_entry, 'r');
			
			$settings = array();
			
			$entry_content = zip_entry_read($zip_entry, zip_entry_filesize($zip_entry));
			$settings['pom'] = $entry_content;

			$xml = new SimpleXMLElement($entry_content);
			// echo $xml->asXML();
			
			// get data from pom:
			$settings['groupId'] = $xml->groupId;
			if (strlen($settings['groupId']) == 0)
			{
				$settings['groupId'] = $xml->parent->groupId;
			}
			
			$settings['artifactId'] = $xml->artifactId;
			
			$settings['version'] = $xml->version;
			if (strlen($settings['version']) == 0)
			{
				$settings['version'] = $xml->parent->version;
			}
			
			$settings['type'] = 'jar';
			
			zip_entry_close($zip_entry);
			
			zip_close($zip);
			
			return $settings;
		}
	}
	
	function getSetting($pom, $setting)
	{
		
		// position of <em:version>
		$open_pos = strpos($pom, "<$setting>");
		if (!$open_pos) {
			return '';
		}
		
		// position of </em:version>
		$close_pos = strpos($pom, "</$setting>", $open_pos);

		// version
		return substr(
			$pom,
			$open_pos + strlen("<$setting>"),
			$close_pos - ($open_pos + strlen("<$setting>")));
	}
	
	function endsWith($haystack, $needle)
	{
    	return $needle === "" || substr($haystack, -strlen($needle)) === $needle;
	}

} // End Welcome

<?php
/**
 * @package Removed Bom
 */
/*
Plugin Name: Removed Bom
Description: Removed Bom
Version: 1.0.0
Author: Jerry
*/

namespace RemovedBom;

function AddLog( $text ) {
	$f= fopen(__DIR__."/log-mini.txt", "a+");
	fwrite($f, $text."\r\n");
	fclose($f);
}

class EachFiles {
	private $dir;
	private $dirs = [];
	private $callbackDir;
	private $callbackFile;
	private $already = "";
	public function __construct($dir, $callbackDir, $callbackFile) {
		if ( strlen($dir) ) {
			$this->dir = rtrim(str_replace("\\","/",$dir),"/")."/";
		}
		$this->dirs = [""];
		$this->callbackDir = $callbackDir;
		$this->callbackFile = $callbackFile;
	}
	public function Scan() {
		$this->shutdown = false;
		$this->complected = false;
		for(reset($this->dirs); ($i = key($this->dirs))!==null;) {
			$dir = $this->dirs[$i];
			unset( $this->dirs[$i] );
			
			$abs_dir = $this->dir . $dir;
			foreach(scandir($abs_dir) as $name) {
				if ( $name === '.' || $name === '..' ) { continue; }
				$path = $dir . $name;
				if ( $isDir = is_dir($abs_dir . $name) ) {
					$path .= "/";
				}
				
				$hash = md5( $path ) . ";";
				if ( strpos($this->already, $hash) !== false ) { continue; }
				$this->already .= $hash;

				if ( $isDir ) {
					$this->AddDir( $path , $name . "/" );
				} else {
					$this->AddFile( $path , $name );
				}
			}
			if ( $this->shutdown ) { break; }
			//usleep(100000);
		}
		$this->complected = true;
	}
	private function AddDir( $path , $name ) {
		$this->dirs[] = $path;
		$this->DoCallback($this->callbackDir,[$this, $path , $name , $this->dir.$path]);
	}
	private function AddFile( $path , $name ) { $this->DoCallback($this->callbackFile,[$this, $path , $name , $this->dir.$path]); }
	private function DoCallback($callback, $params) { @call_user_func_array($callback, $params); }
	
	private $shutdown = false;
	private $complected = false;
	public function Stop() {
		$this->shutdown = true;
	}
	
	public function GetState() {
		if ( $this->complected ) { return ""; }
		return serialize( [$this->dir, $this->dirs, $this->already] );
	}
	public function SetState( $state ) {
		$state = unserialize($state);
		$this->dir = $state[0];
		$this->dirs = $state[1];
		$this->already = $state[2];
	}
}
class Logs {
	private $path;
	public function __construct($key, $dir = null) {
		if ( $dir === null ) { $dir = __DIR__ . "/logs/"; }
		@mkdir($dir,0777,1);
		$this->path = $dir . "/" . md5($key) . ".log";
	}
	public function Clear() {
		@unlink($this->path);
	}
	public function Add($log) {
		$f = fopen($this->path, "a+");
		fwrite($f, $log . "\r\n");
		fclose($f);
	}
	public function Get($line = 0, $maxCount = 1024 ) {
		$res = [];
		if ( @$f = fopen($this->path, "r") ) {
			fseek($f,0);
			while($line--) {
				if ( fgets($f) === false ) { return []; }
			}
			while($maxCount--) {
				if ( false === $_tmp = fgets($f) ) { break; }
				$res[] = trim($_tmp);
			}
		}
		return $res;
	}
}
class LogsSections {
	private $dir;
	private $key;
	private $sections = [];
	public function __construct($key, $dir = null) {
		$this->key = $key;
		$this->dir = $dir;
	}
	public function Clear( $section ) {
		$this->GetSection($section)->Clear();
	}
	public function Add( $section , $log ) {
		$this->GetSection($section)->Add($log);
	}
	public function Get( $section , $line , $maxCount = 1024 ) {
		$this->GetSection($section)->Get($line, $maxCount);
	}
	public function GetSection( $section ) {
		if ( !isset( $this->sections[ $section ] ) ) {
			$this->sections[ $section ] = new Logs( "{$this->key}\x00{$section}" , $this->dir );
		}
		return $this->sections[ $section ];
	}
}
class FilesProcessControl {
	private $dir;
	private $workDir;
	
	private $filterFile;
	private $filterDir;

	private $each;
	
	private $logs;
	
	public function __construct( $dir, $filters = [], $workDir = null ) {
		$this->dir = $dir;
		if ( $workDir === null ) {
			$workDir = __DIR__ . "/file-process-control/";
		}
		$this->workDir = $workDir;
		
		
		$this->filterFile = [@$filters['file'], 'Action'];
		$this->filterDir  = [@$filters['dir'], 'Action'];

		$this->workDir = rtrim($this->workDir, "/") . "/";
		
		@mkdir($this->workDir, 0777, 1);
		
		$hash = md5($this->dir);

		$this->logs = new LogsSections( $hash, $this->workDir . "logs/" );
		foreach($filters as $filter) {
			$filter->SetLog( $this->logs->GetSection("log-filter-process") );
		}
		
		$this->pathState = $this->workDir . $hash . ".state";
		$this->pathLog   = $this->workDir . $hash . ".log";
		$this->pathLock  = $this->workDir . $hash . ".lock";
	}

	public function IsProcess() {
		$lock = !( $f = @fopen($this->pathLock, "c+") AND @flock($f,LOCK_EX|LOCK_NB) );
		@fclose($f);
		return $lock;
	}
	
	public function StartProcess() {
		if ( $this->IsProcess() ) { return; }
		if ( $f = @fopen($this->pathLock, "c+") AND @flock($f,LOCK_EX) ) {
			$this->time = microtime(1);
			$this->each = new EachFiles($this->dir, [$this,"_dir"], [$this,"_file"]);
			$state = @file_get_contents($this->pathState);
			if ( $state ) {
				$this->each->SetState($state);
			} else {
				$this->logs->GetSection("log-filter-process")->Clear();
				$this->logs->GetSection("log-process")->Clear();
			}
			$this->each->Scan();
		}
		if ( !$this->timeOver ) {
			file_put_contents($this->pathState, "");
		}
	}
	
	public function AjProcess() {
		if ( !$this->IsProcess() ) {
			if ( @stat($this->pathState)['size'] ) {
				$this->StartProcess();
				exit;
			}
		}
	}
	
	public function _file($ea, $path, $name, $absPath) {
		$this->TryTime();

		if ( is_callable($this->filterFile) ) {
			$f = $this->filterFile;
			$f( $path, $name, $absPath );
		}
		
		$this->logs->GetSection("log-process")->Add("File: {$path}");
	}
	public function _dir($ea, $path, $name, $absPath) {
		$this->TryTime();

		if ( is_callable($this->filterDir) ) {
			$f = $this->filterDir;
			$f( $path, $name, $absPath );
		}
		
		$this->logs->GetSection("log-process")->Add("Dir: {$path}");
	}

	private $MAX_TIME = 10;
	private $timeOver = false;
	private function TryTime() {
		if ( microtime(1) > $this->time + $this->MAX_TIME ) {
			$this->timeOver = true;
			$this->each->Stop();
			file_put_contents($this->pathState, $this->each->GetState());
		}
	}

	private $time;

	private $pathState;
	private $pathLog;
	private $pathLock;
	
	public function GetLogs() {
		return $this->logs;
	}
}
class FilterFiles {
	private $logs;
	private $pathSettings;
	public function __construct( $listExt = "" ) {
		$listExt = explode(",", $listExt);
		$listExt = array_filter(array_map("\\strtolower",array_map("\\trim",$listExt)), function($v) {
			return (strlen($v));
		});
		$this->listExt = array_flip( $listExt );
	}
	
	public function SetLog( $logs ) {
		$this->logs = $logs;
	}

	private $listExt = [];
	public function Action($path, $name, $absPath) {
		if ( preg_match( "~\.([^\.]*)$~" , $name , $m ) ) {
			if ( isset($this->listExt[ strtolower(trim($m[1])) ]) ) {
				if ( $this->FileProcess( $absPath ) ) {
					$this->logs->Add("Remove BOM: {$path}");
				}
			}
		}
	}
	
	public function FileProcess( $absPath ) {
		$stat = stat($absPath);
		if ( $stat && $stat['size'] >= 3 ) {
			$f = fopen($absPath, "r");
			fseek($f, 0);
			if ( fread($f,3) === "\xEF\xBB\xBF" ) {
				$data = file_get_contents($absPath);
				if ( strlen($data) === $stat['size'] ) {
					while(file_exists($rpath = $absPath . "._".md5(mt_rand(111111111,999999999).microtime(1))."_rz"));
					if ( file_put_contents($rpath, $data) ) {
						$data = substr($data,3);
						if ( file_put_contents($absPath, $data) ) {
							@unlink($rpath);
							fclose($f);
							return true;
						}
					}
				}
			}
		}
		@fclose($f);
		return false;
	}
}

if ( is_admin() ) {
	if ( !$rmExtList = get_option("rm-bom-ext-list") ) {
		update_option("rm-bom-ext-list", "css,js,php");
	}
}
if ( is_admin() && isset($_REQUEST['rm-bom-ajax']) ) {
	$filter = new FilterFiles( get_option("rm-bom-ext-list") );
	$fpc = new FilesProcessControl(ABSPATH, ["file"=>$filter]);
	$fpc->AjProcess();

	switch( $_REQUEST['rm-bom-ajax'] ) {
		case 'get-logs':
			$allI = @(int)$_REQUEST['all-i'];
			$filterI = @(int)$_REQUEST['filter-i'];

			echo json_encode([
				"is_process" => $fpc->IsProcess() ,
				"all" => $fpc->GetLogs()->GetSection("log-process")->Get( $allI ) ,
				"filter" => $fpc->GetLogs()->GetSection("log-filter-process")->Get( $filterI ) ,
			]);

			exit;
		
		case 'start-process':
			$fpc->StartProcess();
			exit;
		
		case 'change-ext-list':
			update_option("rm-bom-ext-list", @$_REQUEST['ext-list']);
			exit;
	}
}

function drawMenu__Main() {
?>
<style>
.out-logs {
	width: 100%;
	height: 300px;
	padding: 20px;
	border: 2px solid #424242;
	border-radius: 10px;
	overflow: scroll;
	margin-top: 10px;
	display: none;
}
.out-logs > div {
	margin: 0px;
	cursor: pointer;
}
.out-logs > div:hover {
	font-size: 20px;
}
.main-cnt, .main-cnt * {
	box-sizing: border-box;
}
.main-cnt {
	padding: 10px;
}
</style>

<div class="wrap">
	<h2>Удаление префикса BOM</h2>
	<input type="button" class="button action button-control btn-rm-bom" disabled value="Запустить">
	<h2>Расшериения файлов в которых проверять префикс BOM</h2>
	<input type="text" name="ext-list" class="ext-list" value="<?php echo get_option("rm-bom-ext-list"); ?>">
	<input type="submit" class="btn-ext-list-change button action button-control" value="Изменить">
</div>

<div class="main-cnt">
	<div class="out-logs logs-all">

	</div>
	<div class="out-logs logs-replace">

	</div>
</div>
<script>
(function($) {
	$(document).ready(function() {
		function _Scroll(select) {
			select = $(select);
			var modeScroll = false;
			this.try = function() {
				var userScroll = select.scrollTop();
				select.scrollTop( 999999999 );
				var maxScroll = select.scrollTop();
				modeScroll = ( userScroll >= maxScroll * 0.95 );
				select.scrollTop(userScroll);
			}
			this.scroll = function() {
				if ( modeScroll ) {
					select.scrollTop( 999999999 );
				}
			}
		}
		var allI = 0;
		var filterI = 0;
		var timeSleep = 100;
		var scrollTop_logsAll = new _Scroll('.logs-all');
		var scrollTop_logsFilter = new _Scroll('.logs-all');
		var process = function() {			
			$.get("?rm-bom-ajax=get-logs&all-i="+allI+"&filter-i="+filterI, function(data) {
				if ( !( data && data.all && data.filter ) ) {
					timeSleep = 700;
					return;
				}
				if ( !data.is_process ) {
					$('.btn-rm-bom').prop('disabled', false);
				}
				if ( allI === 0 && data.all.length > 0 ) { $('.logs-all').fadeIn(); }
				if ( filterI === 0 && data.filter.length > 0 ) { $('.logs-replace').fadeIn(); }
				allI += data.all.length;
				filterI += data.filter.length;
				
				scrollTop_logsAll.try();
				for(var i in data.all) {
					$('.logs-all').append( $('<div></div>').text(data.all[i]).hide().fadeIn() );
				}
				scrollTop_logsAll.scroll();
				
				scrollTop_logsFilter.try();
				for(var i in data.filter) {
					$('.logs-replace').append( $('<div></div>').text(data.filter[i]) );
				}
				scrollTop_logsFilter.scroll();

				timeSleep = ( data.all.length === 0 ) ? 1200 : 100;
			}, "json").always(function() {
				setTimeout(process, timeSleep);
			});
		}
		setTimeout(process, timeSleep);
		
		$(document).on('click', '.btn-rm-bom', function() {
			$.get("?rm-bom-ajax=start-process");
			$(this).prop('disabled', true);
			$('.logs-all').text('').hide();
			$('.logs-replace').text('').hide();
			allI = 0;
			filterI = 0;
			return false;
		});
		$(document).on('click', '.btn-ext-list-change', function() {
			$.get("",{'rm-bom-ajax': 'change-ext-list', 'ext-list': $('.ext-list').val()});
		});
	});
})(jQuery);
</script>
<?php

}

add_action('admin_menu', function() {
	add_options_page('Removed bom', 'Remmoved bom', 8, 'drawMenu__Main', __NAMESPACE__ .'\drawMenu__Main');
});
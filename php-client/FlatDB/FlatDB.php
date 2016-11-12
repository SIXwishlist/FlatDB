<?php

namespace FlatDB;

include_once __DIR__.'/HSClient.php';
include_once __DIR__.'/HSClientUDP.php';
include_once __DIR__.'/HSCommon.php';

use HSS\HSClient;


class FlatDB
{
	static private $conncache = [];
	static private $server_config = [];
	static private $can_add_server = true;

	private $group_id = [];
	private $servers = [];
	private $servers_weight_total = 0;

	static public function addServer( $host, $port, $weight=0, $group=false)
	{
		if (self::$can_add_server !== true)
			die("After creating first object, cannot add any more servers... please add all servers first!");

		if ($group === false)
			$group = "*";
		if (!isset(self::$server_config[$group]))
			self::$server_config[$group] = [];
		self::$server_config[$group][] = [$host, $port, max(1, $weight)];
		return true;
	}

	function __construct( $group = false)
	{
		if (isset(self::$server_config[$group]))
		{
			$this->servers = self::$server_config[$group];
			$this->group_id = $group;
		}
		elseif (isset(self::$server_config['*']))
		{
			$this->servers = self::$server_config['*'];
			$this->group_id = '*';
		}
		else
		{
			error_log("FlatDB - no servers configured, please use FlatDB::addServer");
			$this->group_id = false;
			$this->servers = [];
		}

		$sum = 0;
		foreach ($this->servers as $v)
			$sum += $v[2];
		$this->servers_weight_total = $sum;
		self::$can_add_server = true;
	}

	public function addServers( $servers )
	{
		$added = 0;
		foreach ($servers as $v)
		{
			if (!is_array($v) || count($v) < 2)
			{
				error_log("Incorrect server config, needed: (host, port, [weight], [group])");
				continue;
			};
			if (!isset($v[2]))
				$v[2] = 1;
			if (!isset($v[3]))
				$v[3] = false;
			if (!isset($v[0]) || !isset($v[1]) || !isset($v[2]) || !isset($v[3]))
				continue;

			if ($this->addServer($v[0], $v[1], $v[2], $v[3]))
				$added ++;
		}

		return $added > 0;
	}

	public function getClient($key, $id_only = false)
	{
		if ($id_only && count($this->servers) == 1)
			return 0;
		
		$use = false;
		$use_num = false;
		if (count($this->servers) == 1)
			$use = $this->servers[0];
		elseif (count($this->servers) > 1)
		{
			$key = "{$key}";
			$pos = abs(crc32($key) % $this->servers_weight_total);
			$cmppos = 0;
			foreach ($this->servers as $use_num=>$use)
			{
				$cmppos += $use[2];
				if ($cmppos >= $pos)
					break;
			}
		}
		else
			return false;

		if ($id_only)
			return $use_num;
		
		$key = "{$use[0]}:{$use[1]}";
		if (isset(self::$conncache[$key]))
			return self::$conncache[$key];

		$ret = new HSClient($use[0], $use[1]);
		self::$conncache[$key] = $ret;
		return $ret;
	}

	public function setOption( $option, $value )
	{
		return true;
	}

	public function setOptions( $options )
	{
		return true;
	}

	public function flush( )
	{
		return true;
	}

	public function add($key, $value, $expire = 3600)
	{
		$client = $this->getClient($key);
		if ($expire > 365*3600)
			$expire = $expire - time();

		$packet = ['action'=>'mc-add', 'k'=>$key, 'v'=>$value, 'e'=>$expire];
		$head = [];
		$ret = $client->sendData($packet, $head);
		if (isset($head['mc-error']))
		{
			$this->last_error = $ret;
			return false;
		}

		$this->last_error = false;
		return true;
	}

	public function replace($key, $var, $expire = 3600)
	{
		$client = $this->getClient($key);
		if ($expire > 10*365*3600)
			$expire = $expire - time();

		$packet = ['action'=>'mc-rep', 'k'=>$key, 'v'=>$var, 'e'=>$expire];
		$head = [];
		$ret = $client->sendData($packet, $head);
		if (isset($tmp['mc-error']) && $head['mc-error'])
		{
			$this->last_error = $ret;
			return false;
		}
		
		$this->last_error = false;
		return true;
	}

	public function set($key, $var, $expire = 3600, $safe = false)
	{
		$client = $this->getClient($key);
		if ($expire > 10*365*3600)
			$expire = $expire - time();

		$packet = ['action'=>'mc-set', 'k'=>$key, 'v'=>$var, 'e'=>$expire];
		if ( !($safe === true) )
			$packet['__skipsendback']=true;
		$head = [];
		$ret = $client->sendData($packet, $head);

		// check if the variable was actually set!
		if ($safe === true && isset($head['mc-error']))
		{
			$this->last_error = $ret;
			return false;
		}
		
		$this->last_error = false;
		return true;
	}

	public function touch($key, $expire = 3600)
	{
		$client = $this->getClient($key);
		if ($expire > 10*365*3600)
			$expire = $expire - time();

		$packet = ['action'=>'mc-touch', 'k'=>$key, 'e'=>$expire];
		$head = [];
		$ret = $client->sendData($packet, $head);
		
		if (isset($head['mc-error']))
		{
			$this->last_error = $ret;
			return false;
		}
		
		$this->last_error = false;
		return true;
	}

	public function exists($key, &$out_ttl = false)
	{
		$client = $this->getClient($key);

		$packet = ['action'=>'mc-exists', 'k'=>$key];
		$head = [];
		$ret = $client->sendData($packet, $head);

		if (isset($head['mc-error']))
		{
			$this->last_error = $ret;
			return false;
		}

		$this->last_error = false;
		$out_ttl = $ret;
		return true;
	}

	public function increment($key, $value = 1, $expire = false)
	{
		$client = $this->getClient($key);

		$packet = ['action'=>'mc-inc', 'k'=>$key, 'v'=>$value];
		if ($expire !== false)
		{
			if ($expire > 10*365*3600)
				$expire = $expire - time();
			$packet['e'] = $expire;
		}

		$head = [];
		$ret = $client->sendData($packet, $head);
		if (isset($head['mc-error']))
		{
			$this->last_error = $ret;
			return false;
		}
		
		$this->last_error = false;
		return $ret;
	}

	public function decrement($key, $value = 1, $expire = false)
	{
		$client = $this->getClient($key);

		$packet = ['action'=>'mc-dec', 'k'=>$key, 'v'=>$value];
		if ($expire !== false)
		{
			if ($expire > 10*365*3600)
				$expire = $expire - time();
			$packet['e'] = $expire;
		}

		$head = [];
		$ret = $client->sendData($packet, $head);
		if (isset($head['mc-error']))
		{
			$this->last_error = $ret;
			return false;
		}
		
		$this->last_error = false;
		return $ret;
	}

	public function delete($key)
	{
		$client = $this->getClient($key);

		$packet = ['action'=>'mc-del', 'k'=>$key];
		$head = [];
		$ret = $client->sendData($packet, $head);
		if (isset($head['mc-error']))
		{
			$this->last_error = $ret;
			return false;
		}
		
		$this->last_error = false;
		return true;
	}


	public function get($key, &$out_cas = false, &$out_expires = false)
	{
		$client = $this->getClient($key);

		$packet = ['action'=>'mc-get', 'k'=>$key];
		$advanced = func_num_args() > 1;
		if ($advanced)
			$packet['action'] = 'mca-get';

		$head = [];
		$ret = $client->sendData($packet, $head);

		if ($advanced)
		{
			if (isset($head['cas']) && isset($head['e']) && !isset($head['mc-error']))
			{
				$out_cas = $head['cas'];
				$out_expires = $head['e'];
			}
			else
			{
				$out_cas = false;
				$out_expires = false;
			}
		}

		if (isset($head['mc-error']))
		{
			$this->last_error = $ret;
			return false;
		}
		
		$this->last_error = false;
		return $ret;
	}
	
	public function getFirst(array $keys, &$out_key = false, &$out_cas = false, &$out_expires = false)
	{
		if (count($keys) == 0)
			return false;

		$request_config = [];
		foreach ($keys as $k=>$v)
		{
			$v = "{$v}";
			$client_key = $this->getClient($v, true);
			$request_config[$client_key][] = $v;
		}

		$ret_data = [];
		$num = 0;
		foreach ($request_config as $chunk)
		{
			$client = $this->getClient($chunk[0]);
			$packet = ['action'=>'mca-fget', 'k'=>json_encode($chunk)];
			$ret = $client->sendData($packet, $head);
			if ($ret === false)
				continue;
			
			if (isset($head['mc-error']))
				continue;
			
			$returned_key = $head['k'];
			if ($returned_key === $keys[$num])
			{
				$out_key = $returned_key;
				$out_cas = $head['cas'];
				$out_expires = $head['e'];
				return $ret;
			}
			$ret_data[$returned_key] = [$ret, $head['cas'], $head['e']];
			$num ++;
		}

		foreach ($keys as $k)
		{
			if (!isset($ret_data[$k]))
				continue;
			
			$out_key = $k;
			list ($val, $out_cas, $out_expires) = $ret_data[$k];
			return $val;
		}
		
		$this->last_error = "no key found";
		return false;
	}
	
	public function getMulti(array $keys)
	{
		$output = [];
		if (count($keys) == 0)
			return [];
		
		$request_config = [];
		foreach ($keys as $k=>$v)
		{
			$v = "{$v}";
			$client_key = $this->getClient($v, true);
			$request_config[$client_key][] = $v;
			
			// pre-populate output table so we can keep iten order
			$output[$v] = ['data'=>false, 'cas'=>false, 'e'=>false];
		}	

		foreach ($request_config as $chunk)
		{
			$client = $this->getClient($chunk[0]);
			$packet = ['action'=>'mca-mget', 'k'=>json_encode($chunk)];

			$head = [];
			$ret = $client->sendData($packet, $head);
			if ($ret !== false)
			{
				$pos = 0;
				for ($item = 0; $item < count($chunk); $item++)
				{
					$dataheader = unpack("V1cas/V1e/V1ds", substr($ret, $pos, 12));
					$datasize = $dataheader['ds'];
					$cas = $dataheader['cas'];
					$e = $dataheader['e'];
					if ($cas == 0)
						$cas = false;
					if ($e == 0)
						$e = false;
					
					$pos += 12;
					$output[$chunk[$item]] = ['data'=>substr($ret, $pos, $datasize), 'cas'=>$cas, 'e'=>$e];
					$pos += $datasize;	
				}
			}
		}
		
		return $output;
	}
	
	
	const ADVI_INSERT_CAS_MISMATCH = 1;
	const ADVI_INSERT_OUT_OF_MEMORY = 2;
	const ADVI_CONN_ERROR = 2;
	public function atomicAdvancedInsert($key, $value = false, $cas = false, $expire = 3600)
	{
		$client = $this->getClient($key);
		if ($expire > 30*3600)
			$expire = $expire - time();

		$packet = ['action'=>'mca-insert', 'k'=>$key, 'e'=>$expire];
		if ($value !== false)
			$packet['v'] = $value;
		if ($cas !== false)
			$packet['cas'] = $cas;
		
		$head = [];
		$ret = $client->sendData($packet, $head);
		if ($ret === false)
			return [self::ADVI_CONN_ERROR];
			
		$cas = false;
		if (isset($head['cas']))
			$cas = $head['cas'];
		
		if (isset($head['mc-error']))
		{
			$value = false;
			if (isset($head['mc-curr-val']))
				$value = $ret;
			
			$this->last_error = "atomic set error, {$head['mc-error']}";
			return [$head['mc-error'], $value, $cas];
		}

		$this->last_error = false;
		return [true, $cas];
	}
	
	public function atomicSet($key, $expire, $get_val_fn, &$out_new_cas = false)
	{
		$curr_cas = false;
		$curr_value = $this->get($key, $curr_cas);
		
		while(true)
		{
			$new_value = $get_val_fn($curr_value);
			
			$result = $this->atomicAdvancedInsert($key, $new_value, $curr_cas, $expire);
			if ($result[0] === true)
			{
				$curr_cas = $result[1];
				$curr_value = $new_value;

				$out_new_cas = $curr_cas;
				return $curr_value;
			}
			
			if ($result[0] !== self::ADVI_INSERT_CAS_MISMATCH)
				return false;
			
			$curr_value = $result[1];
			$curr_cas = $result[2];
		}
		
		return false;
	}
}

<?php

namespace App\Http\Controllers;

use App\Models\{
	BitLookup,
	DLookup,
	InterpTable,
	WorldArea,
	WorldRoom
};
use Doctrine\DBAL\Schema\{Column, Index};
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Log, Storage};

class UtilitiesController extends Controller
{
	/**
	 * Default header for autogenerated files.
	 *
	 * @var string
	 */
	private $genHeader = "/* This file is auto-generated by Riftshadow Admin Website. Do not modify. */\n\n";

	/**
	 * Instantiate a new controller instance.
	 *
	 * @return void
	 */
	public function __construct()
	{
		// Users lower than trust level(Tru or Levl < 58) not permitted
		$this->middleware('admin:58');
	}

	/**
	 * Generate the c files for use by the server
	 *
	 * @param Request $request
	 * @return Illuminate\Http\Response
	 */
	public function genDefs(Request $request)
	{
		$tables = collect(
			DB::database('rift_core')
				->getDoctrineSchemaManager()
				->listTableNames()
		);

		$tables = $tables->mapWithKeys(function ($table) {
			$columns = collect(
				DB::database('rift_core')
					->getDoctrineSchemaManager()
					->listTableColumns($table)
			)->map(function ($column) use ($table) {
				$name = $column->getName();
				$dName = strtoupper("COL_{$table}_{$name}");
				$canGo = $this->canGo($column, $dName);
				$flags = $this->getFlags($table, $column);
				$type = $this->getType($column);
				return compact([
					'canGo',
					'column',
					'name',
					'flags',
					'type'
				]);
			})->values();
			return [$table => $columns];
		});

		$coldefs = $this->genColDefs($tables);
		$fundefs = $this->genFunDefs();
		$tempinterps = $this->genTempInterps();
		$stddefs = $this->genStdDefs();

		$defPath = basename(env('CODE_DIRECTORY')).'/';

		Storage::disk('code')->put('autogen/coldefs.h', $coldefs);
		Storage::disk('code')->put('autogen/fundefs.h', $fundefs);
		Storage::disk('code')->put('temp_interp.c', $tempinterps);
		Storage::disk('code')->put('autogen/stddefs.h', $stddefs);

		return redirect()
			->route('home')
			->with(
				'message',
				view('utilities.message', compact('defPath'))->render()
			);
	}

	/**
	 * Updates the order of world areas and applies those changes to world rooms.
	 *
	 * @param Request $request
	 * @return \Illuminate\Http\Response
	 */
	public function updateAreaIds(Request $request)
	{
		$error = '';
		WorldArea::orderBy('min_vnum')->chunk(100, function ($worldAreas) use ($error) {
			// TODO: Make this faster if it is slow
			try {
				foreach ($worldAreas as $idx => $worldArea) {
					$name = $worldArea->name;
					// Update order of World Areas
					$worldAreaUpdate = WorldArea::where('name', $name)->first();
					$worldAreaUpdate->id = $idx;
					$worldAreaUpdate->save();

					// Update World Rooms area_id
					$worldRoom = WorldRoom::where('vnum', '>=', $worldArea->min_vnum)
						->where('vnum', '<=', $worldArea->max_vnum)
						->first();
					$worldRoom->area_id = $idx;
					$worldRoom->save();
				}
			} catch (Exception $e) {
				$error .= "\n{$e->getMessage()}";
			}
		});

		$redirect = redirect()->route('home');

		if ($error === '') {
			return $redirect
				->with('message', trans('utilities.updateAreaIds'));
		} else {
			return $redirect->with('message', $error);
		}
	}

	/**
	 * Reorder world room ids.
	 *
	 * @param Request $request
	 * @return Illuminate\Http\Response
	 */
	public function updateRoomIds(Request $request)
	{
		// Not implemented yet
		// TODO: Implement
		
		$redirect = redirect()->route('home');

		return $redirect->with('message', trans('utilities.updateRoomIds'));
	}

	/**
	 * Determine if subsequent templating may proceed
	 *
	 * @param Column $column
	 * @param string $dName
	 * @return boolean
	 */
	private function canGo(Column $column, string $dName): bool
	{
		$go = true;
		if ($column->getAutoincrement()) {
			$go = false;
		}

		if (
			$column->getType() === 'string' &&
			count($this->getFlags()) === 0
		) {
			$go = false;
		}

		if ($dName === 'COL_WORLD_AREAS_ID') {
			$go = false;
		}

		return $go;
	}

	/**
	 * Get the flags for a column.
	 * Only covers: not_null, primary_key, unique_key, multiple_key, unsigned,
	 * auto_increment
	 *
	 * @param string $table
	 * @param Column $column
	 * @return array
	 */
	private function getFlags(string $table, Column $column): array
	{
		$isPrimary = false;
		$isUnique = false;
		$isMultiple = false;
		if ($index = $this->getIndex($table, $column)) {
			$isPrimary = $index->isPrimary();
			$isUnique = $index->isUnique();
			$isMultiple = count($index->getColumns()) > 1;
		}
		$returnArr = [];
		if ($column->getNotnull()) {
			$returnArr[] = 'not_null';
		}
		if ($isPrimary) {
			$returnArr[] = 'primary_key';
		}
		if ($isUnique) {
			$returnArr[] = 'unique_key';
		}
		if ($isMultiple) {
			$returnArr[] = 'multiple_key';
		}
		if ($column->getUnsigned()) {
			$returnArr[] = 'unsigned';
		}
		if ($column->getAutoincrement()) {
			$returnArr[] = 'auto_increment';
		}

		return $returnArr;
	}

	/**
	 * Determine whether the column in the given table is an index.
	 *
	 * @param string $table The table to look into
	 * @param Column $column The column to check against
	 * @return Index|bool
	 */
	private function getIndex($table, Column $column)
	{
		$indexes = DB::database('rift_core')
			->getDoctrineSchemaManager()
			->listTableIndexes($table);
		$found = false;
		foreach ($indexes as $index) {
			foreach ($index->getColumns() as $idxColumn) {
				if ($column->getName() === $idxColumn) {
					$found = $index;
					break 2;
				}
			}
		}
		return $found;
	}

	/**
	 * Get the base type for a column
	 *
	 * @param Column $column
	 * @return string
	 */
	private function getType(Column $column)
	{
		$type = '';
		switch ($column->getType()) {
			case '\Text':
			case '\String':
				$type = 'string';
				break;
			case '\SmallInt':
			case '\Integer':
			case '\BigInt':
				$type = 'int';
				break;
			case '\Float':
			case '\Double':
			case '\Decimal':
				$type = 'real';
				break;
			case '\DateTime':
				$type = 'datetime';
				break;
		}

		return $type;
	}

	/**
	 * Generate the column defs string
	 *
	 * @param Collection $tables
	 * @return string
	 */
	private function genColDefs(Collection $tables): string
	{
		$content = $this->genHeader;

		foreach ($tables as $tableName => $columns) {
				$m_var = "";
		
			if ($tableName === "world_rooms") {
				$m_var .= "#define\tLOAD_EXIT_DATA(eptr)\\\n";
				$m_var .= "eptr->exit_info = eptr->RowToNumber(COL_WORLD_ROOMS_0_EXIT_INFO);\\\n";
				$m_var .= "eptr->key = eptr->RowToNumber(COL_WORLD_ROOMS_0_KEY);\\\n";
				$m_var .= "eptr->keyword.NoDeallocSetTo(COL_WORLD_ROOMS_0_KEYWORD);\n\n";
			}
		
			$m_var .= "#define\tLOAD_" . strtoupper($tableName) . "(tvar)\\\n";
		
			if ($tableName === "world_rooms") {
				$m_var .= "ASSOCIATE_AREA(tvar, row[COL_WORLD_ROOMS_AREA_ID]);\\\n";
			}

			$i = 0;
			
			foreach ($columns as $colData) {
				$f_name = $colData['name'];
				$f_type = $colData['type'];
				$flags = $colData['flags'];
				$first_flag = $flags[0] ?? '';
				$d_name = strtoupper("COL_{$tableName}_{$f_name}");
				$line = "#define\t$d_name\t\t$i\n";
		
				$go = true;
				foreach ($flags as $flag) {
					if ($flag === "auto_increment") {
						$go = false;
						break;
					}
				}
		
				if ($f_type === "string" && $first_flag === "") {
					$go = false;
				}
				if ($f_name === "max_str") {
					$f_name = "max_stats[0]";
				}
				if ($f_name === "max_int") {
					$f_name = "max_stats[1]";
				}
				if ($f_name === "max_wis") {
					$f_name = "max_stats[2]";
				}
				if ($f_name === "max_dex") {
					$f_name = "max_stats[3]";
				}
				if ($f_name === "max_con") {
					$f_name = "max_stats[4]";
				}
		
		
				// world related id columns that shouldn't show up
				if ($d_name === "COL_WORLD_AREAS_ID") {
					$go = 0;
				}
				// exit info for world_rooms that doesn't need to display.
				if (
					(
						strpos($f_name, "_to_room") === 1 ||
						strpos($f_name, "_exit_info") === 1 ||
						strpos($f_name, "_key") === 1 ||
						strpos($f_name, "_keyword") === 1
					) && $tableName === "world_rooms"
				) {
					$content .= $line;
					$go = 0;
				}
		
				if ($go) {
					$content .= $line;
					if ($f_name === "do_fun_name") {
						$m_var .= "tvar.do_fun.SetPtr((void *)do_fun_table[i].name);";
					} elseif ($f_type === "int") {
						if ($first_flag === "") {
							$m_var .= "tvar.$f_name.SetToDouble(row[$d_name]);";
						} else {
							$m_var .= "tvar.$f_name = row.RowToNumber($d_name);";
						}
					} elseif (
						$f_type === "string" ||
						$f_type === "blob" ||
						$f_type === 'datetime' // TODO: Fix this hack please
					) {
						$m_var .= "tvar.$f_name.NoDeallocSetTo(row[$d_name]);";
					} elseif ($f_type === "real") {
						$m_var .= "tvar.$f_name = row.RowToFloat($d_name);";
					} else {
						die("Error with field types! - $f_name, $f_type");
					}
		
					// Add the allocate_exits macro for loading rooms from
					// world_rooms - needs to be near the end.
					if ($d_name === "COL_WORLD_ROOMS_OWNER") {
						$m_var .= "\\\n";
						$m_var .= "ALLOCATE_EXITS(tvar);";
					}

					$autoIncrementNext = false;
					$count = $columns->count();
					if (
						$columns->has($i + 1) &&
						$columns->get($i + 1)['column']->getAutoincrement()
					) {
						$autoIncrementNext = true;
					} else {
						$count--;
					}
					
					if (!$autoIncrementNext && $i < $count) {
						$m_var .= "\\";
					}
		
					$m_var .= "\n";
				}
				$i++;
			}
			$content .= "\n$m_var\n";
		}

		// Remove the last \n
		return substr($content, 0, -1);
	}

	/**
	 * Generate function definitions
	 *
	 * @return string
	 */
	private function genFunDefs()
	{
		$content = $this->genHeader;
		
		/* kick out the interp do_funs */
		$dofunargs = "CCharacter *ch, const char *args";

		$content .= "typedef void (*DOFUNTYPE) ($dofunargs);\n";
		$content .= "struct do_fun_type{ DOFUNTYPE name; };\n";
		
		/* we have to prototype the do_funs first */
		$interps = InterpTable::select('do_fun_name', 'id')
			->distinct()
			->orderBy('id', 'asc')
			->get();
		
		foreach ($interps as $interp) {
			$content .= "void $interp->do_fun_name ($dofunargs);\n";
		}

		$content .= "const struct do_fun_type do_fun_table[] =\n{\n";

		foreach ($interps as $interp) {
			$content .= "\t{ $interp->do_fun_name },\n";
		}

		$content .= "};\n";
		
		return $content;
	}

	/**
	 * Generate temperate interps
	 *
	 * @return string
	 */
	private function genTempInterps()
	{
		$content = $this->genHeader;

		$content .= "#include \"character.h\"\n\n\n";

		$dofunargs = "CCharacter *ch, const char *args";

		$interps = InterpTable::select('do_fun_name', 'id')
			->distinct()
			->orderBy('id', 'asc')
			->get();

		foreach ($interps as $interp) {
			$content .= "void $interp->do_fun_name($dofunargs)\n{\n\treturn;\n}\n\n";
		}
		
		// Remove last \n
		return substr($content, 0, -1);
	}

	/**
	 * Generate std definitons
	 *
	 * @return string
	 */
	private function genStdDefs()
	{
		$content = $this->genHeader;
		
		$bitLookups = BitLookup::select('define', 'bit')->get();

		foreach ($bitLookups as $bitLookup) {
			$content .= "#define $bitLookup->define\t\t\t$bitLookup->bit\n";
		}

		$dLookups = DLookup::select('define', 'value')->get();

		foreach ($dLookups as $dLookup) {
			$content .= "#define $dLookup->define\t\t\t$dLookup->value\n";
		}

		return $content;
	}
}

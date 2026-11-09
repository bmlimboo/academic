<?php
/** Date row, period row, type row and classroom row; one student per row. */
function saMatrix(array $r,bool $template=false):array{
 $sessions=[];$students=[];
 if(!$template)foreach($r['entries']as $v){$sid=$v['student_id'];$students[$sid]??=['name'=>$v['student_name'],'cells'=>[]];foreach($v['periods']as $period=>$status)if($status!==''){$key=$v['date'].'|'.$period;$sessions[$key]=[$v['date'],$period,$v['class_type'],$v['classroom']];$students[$sid]['cells'][$key]=$status;}}
 ksort($sessions);if($template)foreach(acPeriods()as $period)$sessions['sample|'.$period]=['YYYY-MM-DD',$period,'Regular',$r['metadata']['classroom']];
 $rows=[['Date','',''],['Sl. No.','Student ID','Student Name'],['Class Type','',''],['Classroom','','']];foreach($sessions as $s){$rows[0][]=$s[0];$rows[1][]=$s[1];$rows[2][]=$s[2];$rows[3][]=$s[3];}
 if($template)for($c=4;$c<count($rows[0]);$c++)$rows[0][$c]='';
 $n=0;foreach($students as $id=>$s){$row=[++$n,(string)$id,$s['name']];foreach($sessions as $key=>$unused)$row[]=$s['cells'][$key]??'';$rows[]=$row;}return $rows;
}
function saParseMatrix(array $r,array $rows):array{
 if(trim(ltrim((string)($rows[0][0]??''),"\xEF\xBB\xBF"))!=='Date'||($rows[1][1]??'')!=='Student ID'){
  // Keep the earlier flat template compatible, including a date entered once per group.
  $dateIndex=array_search('Date',$rows[0]??[],true);$last='';if($dateIndex!==false)foreach($rows as $i=>&$row){if(!$i)continue;if(trim((string)($row[$dateIndex]??''))!=='')$last=trim($row[$dateIndex]);else $row[$dateIndex]=$last;}unset($row);return acParseRows($r,$rows);
 }
 acAssert(($rows[1][2]??'')==='Student Name'&&($rows[2][0]??'')==='Class Type'&&($rows[3][0]??'')==='Classroom','Use the date-across-top template with Date, Class Type and Classroom header rows.');
 $cols=count($rows[1]);acAssert($cols>=4&&$cols<=403,'Use 1–400 session columns per file.');$sessions=[];$date='';$seen=[];foreach($rows as $index=>$row)acAssert(!array_filter(array_slice($row,$cols),fn($v)=>trim((string)$v)!==''),'Row '.($index+1).': data extends beyond the period headings.');
 for($c=3;$c<$cols;$c++){$cell=trim((string)($rows[0][$c]??''));if($cell!=='')$date=acDate($cell);acAssert($date!=='','Enter a date above each group of periods.');$period=trim($rows[1][$c]);acAssert(in_array($period,acPeriods(),true),'Column '.($c+1).': use a period from the template.');$key=$date.'|'.$period;acAssert(!isset($seen[$key]),'Duplicate date and period: '.$key);$seen[$key]=true;$type=trim($rows[2][$c]??'');acAssert(in_array($type,['Regular','Extra'],true),'Class Type must be Regular or Extra for every period.');$sessions[$c]=[$date,$period,$type,trim($rows[3][$c]??'')?:$r['metadata']['classroom']];}
 $flat=[acHeaders($r)];$ids=[];foreach(array_slice($rows,4)as $n=>$row){if(!array_filter($row,fn($v)=>trim((string)$v)!==''))continue;$id=trim((string)($row[1]??''));acAssert(!isset($ids[$id]),'Student ID repeated at spreadsheet row '.($n+5).'. Use one row per student.');$ids[$id]=true;$byDay=[];
 foreach($sessions as $c=>[$date,$period,$type,$room]){$status=strtoupper(trim((string)($row[$c]??'')));acAssert(in_array($status,['','P','A'],true),'Row '.($n+5).', '.$date.' '.$period.': enter P or A.');if($status==='')continue;$key=$date.'|'.$type;$byDay[$key]??=[$date,$id,trim((string)($row[2]??'')),$room,$type,...array_fill(0,8,'')];acAssert($byDay[$key][3]===$room,'Use one classroom per date/class type.');$byDay[$key][5+array_search($period,acPeriods(),true)]=$status;}
 acAssert((bool)$byDay,'Row '.($n+5).': student has no attendance.');foreach($byDay as $v)$flat[]=$v;
 }return acParseRows($r,$flat);
}
function saReadFile(array $f):array{
 acAssert(($f['error']??1)===UPLOAD_ERR_OK&&($f['size']??0)<=10*1024*1024&&is_uploaded_file($f['tmp_name']??''),'Choose a CSV or XLSX up to 10 MB.');$ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));acAssert(in_array($ext,['csv','xlsx'],true),'Choose CSV or XLSX.');if($ext==='csv')return readSheet($f['tmp_name'],$f['name']);
 $z=new ZipArchive();acAssert($z->open($f['tmp_name'])===true,'Invalid XLSX.');$size=0;for($i=0;$i<$z->numFiles;$i++)$size+=$z->statIndex($i)['size'];$z->close();acAssert($size<=40*1024*1024,'Workbook is too large.');$reader=new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();$info=$reader->listWorksheetInfo($f['tmp_name']);acAssert($info&&$info[0]['totalRows']<=5004&&$info[0]['totalColumns']<=403&&$info[0]['totalRows']*$info[0]['totalColumns']<=200000,'Use at most 5,000 students, 400 periods and 200,000 cells per file.');$reader->setLoadSheetsOnly($info[0]['worksheetName']);$book=$reader->load($f['tmp_name']);$sheet=$book->getSheet(0);$rows=[];
 for($i=1;$i<=$info[0]['totalRows'];$i++){$row=[];for($c=1;$c<=$info[0]['totalColumns'];$c++){$cell=$sheet->getCell([$c,$i]);acAssert($cell->getDataType()!=='f','Upload values only, not formulas.');$v=$cell->getValue();if($v instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText)$v=$v->getPlainText();if(is_numeric($v)&&(($i===1&&$c>=4)||($i>1&&($rows[0][$c-1]??'')==='Date')))$v=\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$v)->format('Y-m-d');$row[]=trim((string)$v);}$rows[]=$row;}$book->disconnectWorksheets();return $rows;
}
function saMerge(array $r,array $old,array $new,bool $protect):array{
 $map=[];foreach($old as $v)$map[$v['student_id'].'|'.$v['date'].'|'.$v['class_type']]=$v;
 foreach($new as $v){$key=$v['student_id'].'|'.$v['date'].'|'.$v['class_type'];if(isset($map[$key])){$existing=$map[$key];acAssert($existing['student_name']===$v['student_name']&&$existing['classroom']===$v['classroom'],'Student name or classroom differs from the existing record. Ask Exam Cell / DAA to correct it.');foreach($v['periods']as $period=>$status)if($status!==''){acAssert(!$protect||$existing['periods'][$period]===''||$existing['periods'][$period]===$status,'Previously submitted attendance differs for '.$v['student_id'].' · '.$v['date'].' · '.$period.'. Ask Exam Cell / DAA to correct it.');$existing['periods'][$period]=$status;}$map[$key]=$existing;}else $map[$key]=$v;}
 $result=array_values($map);acValidateEntries($r,$result,false);return $result;
}
function saMatrixHtml(array $r):void{
 $rows=saMatrix($r);echo '<div class="table-wrap"><table class="sa-register"><thead><tr><th colspan="3">Date</th>';$dates=array_slice($rows[0],3);for($i=0;$i<count($dates);){$n=1;while(isset($dates[$i+$n])&&$dates[$i+$n]===$dates[$i])$n++;echo '<th colspan="'.$n.'">'.e($dates[$i]).'</th>';$i+=$n;}echo '</tr><tr>';foreach($rows[1]as $h)echo '<th>'.e($h).'</th>';echo '</tr>';foreach([2,3]as $ri){echo '<tr>';foreach($rows[$ri]as $v)echo '<th>'.e($v).'</th>';echo '</tr>';}echo '</thead><tbody>';foreach(array_slice($rows,4)as $row){echo '<tr>';foreach($row as $v)echo '<td>'.e($v===''?'—':$v).'</td>';echo '</tr>';}echo '</tbody></table></div>';
}

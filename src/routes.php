<?php

use Slim\Http\Request;
use Slim\Http\Response;
use Slim\Http\UploadedFile;
use Slim\Views\PhpRenderer;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$container = $app->getContainer();
$container['renderer'] = new PhpRenderer("../templates");
$container['db'] = function ($c){
    $settings = $c->get('settings')['db'];
    $server = $settings['driver'].":host=".$settings['host'].";dbname=".$settings['dbname'];
    $conn = new PDO($server, $settings["user"], $settings["pass"]);  
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $conn;
};
$container['upload_directory'] = __DIR__ . '/../public/uploads/';
// Routes
$app->post("/login/", function (Request $request, Response $response){

    $userData = $request->getParsedBody();
    $sql = "SELECT iduser,nama,uname FROM `user`
            WHERE uname=:uname AND pwd=:pwd";
    $stmt = $this->db->prepare($sql);
    
    $stmt->execute([":uname" => $userData['username'],
                    ":pwd" => md5($userData['password'])]);

    $result = $stmt->fetch();
    return $response->withJson(["status" => "success", "data" => $result], 200);
});

$app->get("/item/{param}/", function (Request $request, Response $response, $args)use ($container){
    $param = $args["param"];
    $result=getData($container,$param);
    $Amber=cekAmber($container,$param);
    $result['noTiket']=-1;
    $result['rowTiket']=-1;
    $result['rowAmber']=$Amber['Row'];
    if($Amber['Row']>0){
        $result['daftarAmber']=$Amber['Daftar'];
        $Tiket=getNoTiket($container,$param);
        $result['noTiket']=$Tiket['No'];
        $result['rowTiket']=$Tiket['Row'];
    }
    
    return $response->withJson(["status" => "success","statusCode" => "1", "data" => $result], 200);
});
$app->get("/getShift", function (Request $request, Response $response, $args)use($container){
    $sesiChecklist=cekShift($container);
    if(!$sesiChecklist)
        $sesiChecklist='-';
    return $response->withJson(["status" => "success","statusCode" => "1", "data" => $sesiChecklist], 200);
});
$app->get("/getRak", function (Request $request, Response $response, $args)use($container){
    
    // $sql = "SELECT LEFT(A.`rak_name`,1) AS RAK
    // FROM dc_rak A
    // GROUP BY LEFT(A.`rak_name`,1)";

    // $sql = "SELECT A.`rak_name` AS RAK,A.`id_rak`
    // FROM dc_rak A";
    $sesiChecklist=cekShift($container);
    $sql="SELECT id_rak,RAK,(JUM-JUM_CEK) AS `STATUS`
    FROM(
    SELECT `A`.`id_rak`,B.`rak_name` AS RAK,COUNT(`A`.`id_rak`) AS JUM,
        (
        SELECT COUNT(DISTINCT AA.`id_server`) AS jum_cek
        FROM dc_checklist AA
        INNER JOIN dc_location CC ON CC.`id_server`=AA.`id_server`
        INNER JOIN dc_server DD ON DD.`id_server`=AA.`id_server` AND DD.`poweroff`=1
        WHERE DATE_FORMAT(AA.`waktu`,'%Y-%m-%d')=DATE_FORMAT(NOW(),'%Y-%m-%d') AND AA.`sesi_checklist`=:sesiCeklis AND CC.`id_rak`=A.`id_rak`
        ) AS JUM_CEK
        FROM `dc_location` `A`
        INNER JOIN `dc_rak` `B` ON `B`.`id_rak` = `A`.`id_rak`
        INNER JOIN `dc_server` `C` ON `C`.`id_server` = `A`.`id_server`
        WHERE C.`poweroff`=1
        GROUP BY `A`.`id_rak`
        ) Z
        ORDER BY RAK";
    

    $stmt = $this->db->prepare($sql);
    // $stmt->execute();
    $stmt->execute([":sesiCeklis" => $sesiChecklist]);
    $result = $stmt->fetchAll();
    
    return $response->withJson(["status" => "success","statusCode" => "1", "data" => $result], 200);
});
$app->get("/daftarItem/{param}/", function (Request $request, Response $response, $args)use($container){
    $param = $args["param"];
    $sesiChecklist=cekShift($container);
    $sql = "SELECT A.id_server,C.servername,B.`sn`,CONCAT(A.u,'-',(A.`u`+(C.`u_count`-1)), ' Urutan : ', COALESCE(A.`urutan_h`,'-')) AS u,A.urutan_h,COALESCE(B.`foto`,'none.png') AS foto,
            D.kondisi,B.poweroff,
            (SELECT COUNT(DISTINCT(id_server))
                    FROM dc_checklist
                    WHERE id_server=B.`id_server` AND DATE_FORMAT(waktu,'%Y-%m-%d')=DATE_FORMAT(NOW(),'%Y-%m-%d') AND sesi_checklist=:sesiCeklis) AS FLAG
            FROM dc_location A 
            LEFT JOIN dc_server B ON B.`id_server`=A.`id_server`
            INNER JOIN dc_servermachine C ON C.`id_servermachine`=B.`id_servermachine`
            LEFT JOIN dc_checklist D ON D.`id_server`=A.`id_server` AND D.`sesi_checklist`=:sesiCeklis  AND DATE_FORMAT(waktu,'%Y-%m-%d')=DATE_FORMAT(NOW(),'%Y-%m-%d')
            WHERE A.`id_rak`=:idRak 
            GROUP BY A.`id_server`
            ORDER BY FLAG,A.u,A.`urutan_h`,B.poweroff DESC";
    $stmt = $this->db->prepare($sql);
    $stmt->execute([":idRak" => $param,
                    ":sesiCeklis" => $sesiChecklist]);
    $result = $stmt->fetchAll();

    $sqlRakName="SELECT A.`rak_name` as rakName
            FROM dc_rak A
            WHERE A.`id_rak`=:idRak";
    $stmtRakName = $this->db->prepare($sqlRakName);
    $stmtRakName->execute([":idRak" => $param]);
    $resultRakName = $stmtRakName->fetchAll()[0];
    return $response->withJson(["status" => "success","statusCode" => "1", "data" => $result, "rakName" => $resultRakName], 200);
});
//catatan untuk ordo cukup yang ini yang dirubah
$app->post('/simpan/{id}', function(Request $request, Response $response, $args)use($container) {
    $idServer=$args['id'];
    $sesiChecklist=cekShift($container);
    //2 baris dibawah dikomen kalo gak bisa masukin kondisi normal
    $cekEksis=cekEksisData($container,$idServer,$sesiChecklist);
    if($cekEksis){
        $uploadedFiles = $request->getUploadedFiles();
        $itemDetail = $request->getParsedBody();
        $keterangan=(empty($itemDetail['keterangan']) ? null : $itemDetail['keterangan']);
        $waktu=date("Y-m-d H:i:s");
        
        

        // handle single input with single file upload
        $uploadedFile = $uploadedFiles['photo'];
        if ($uploadedFile->getError() === UPLOAD_ERR_OK) {
            $filename=null;
            if($uploadedFile->getSize()>0){            
                $extension = pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION);            
                $filename = date("YmdHis").'.'.$extension;
                
                $directory = $this->get('settings')['upload_directory'];
                $uploadedFile->moveTo($directory . DIRECTORY_SEPARATOR . $filename);
            }
            $lastKondisi=cekLastKondisi($container,$idServer);
            $lastTiket=cekLastTiket($container,$idServer);
            $resUpdateTiket=true;
            
            if(!empty($lastTiket)){
                if($lastKondisi==0){
                    $resUpdateTiket=updateTiket($container,$lastTiket);
                }
            }
            if($resUpdateTiket){
                // simpan nama file ke database
                $sql = "INSERT INTO dc_checklist (id_user,id_server,waktu,kondisi,keterangan,foto,sesi_checklist)
                VALUES (:id_user,:id_server,:waktu,:kondisi,:keterangan,:foto,:sesi_checklist)";
                $stmt = $this->db->prepare($sql);
                $params = [
                    ":id_user" => $itemDetail['id_user'],
                    ":id_server" => $itemDetail['id_server'],
                    ":waktu" => $waktu,
                    ":kondisi" => $itemDetail['kondisi'],
                    ":keterangan" => $keterangan,
                    ":foto" => $filename,
                    ":sesi_checklist" => $sesiChecklist,  
                ];
                if($stmt->execute($params)){
                    return $response->withJson(["status" => "success", "data" => $uploadedFile->getSize()], 200);
                }
                return $response->withJson(["status" => "failed", "data" => "0"], 200);    
            }else{
                return $response->withJson(["status" => "failed", "data" => "0"], 200);    
            }
        }
        //3 baris dibawah dikomen kalo gak bisa masukin kondisi normal
    }else{
        return $response->withJson(["status" => "success", "data" => "1"], 200);
    }
});

$app->post('/simpanPowerOff/{id}', function(Request $request, Response $response, $args)use($container) {
    $idServer=$args['id'];
    $uploadedFiles = $request->getUploadedFiles();
    $itemDetail = $request->getParsedBody();
    $keterangan=(empty($itemDetail['keterangan']) ? null : $itemDetail['keterangan']);
    $waktu=date("Y-m-d H:i:s");
    
    $sesiChecklist=cekShift($container);

    // handle single input with single file upload
    $uploadedFile = $uploadedFiles['photo'];
    
    $filename=null;
    $lastKondisi=cekLastKondisi($container,$idServer);
    $lastTiket=cekLastTiket($container,$idServer);
    $resUpdateTiket=true;
    
    if(!empty($lastTiket)){
        if($lastKondisi==0){
            $resUpdateTiket=updateTiket($container,$lastTiket);
        }
    }
    if($resUpdateTiket){
        // simpan nama file ke database
        $sql = "INSERT INTO dc_checklist (id_user,id_server,waktu,kondisi,keterangan,foto,sesi_checklist)
        VALUES (:id_user,:id_server,:waktu,:kondisi,:keterangan,:foto,:sesi_checklist)";
        $stmt = $this->db->prepare($sql);
        $params = [
            ":id_user" => $itemDetail['id_user'],
            ":id_server" => $itemDetail['id_server'],
            ":waktu" => $waktu,
            ":kondisi" => $itemDetail['kondisi'],
            ":keterangan" => $keterangan,
            ":foto" => $filename,
            ":sesi_checklist" => $sesiChecklist,  
        ];
        if($stmt->execute($params)){
            $resUpdatePower=updatePower($container,$itemDetail['id_server']);
            if($resUpdatePower){
                return $response->withJson(["status" => "success", "data" => "1"], 200);
            }
        }
        return $response->withJson(["status" => "failed", "data" => "0"], 200);           
    }else{
        return $response->withJson(["status" => "failed", "data" => "0"], 200);    
    }
    
});
$app->post('/simpanPowerOn/{id}', function(Request $request, Response $response, $args)use($container) {
    $idServer=$args['id'];
    $sql="UPDATE dc_server SET poweroff=:poweroff WHERE id_server=:idServer";
    $stmt = $this->db->prepare($sql);
    $res=$stmt->execute([":poweroff" => 1,
                    ":idServer" => $idServer]);
    if($res){
        return $response->withJson(["status" => "success", "data" => "1"], 200);
    }
    return $response->withJson(["status" => "failed", "data" => "0"], 200);           
   
    
});

$app->post('/simpanAmberMultiple/{id}', function(Request $request, Response $response, $args) use($container) {
    $itemDetail = $request->getParsedBody();
    $flag=0;
    $itemAmber=json_decode($itemDetail['item_amber']);
    foreach ($itemAmber as $key => $value) {
        $keterangan=(empty($value->keterangan) ? null : $value->keterangan);
        $waktu=date("Y-m-d H:i:s");
        $sesiChecklist=cekShift($container);
        // simpan nama file ke database
        $sql = "INSERT INTO dc_checklist (id_user,id_server,id_notiket,waktu,kondisi,keterangan,foto,sesi_checklist)
        VALUES (:id_user,:id_server,:id_notiket,:waktu,:kondisi,:keterangan,:foto,:sesi_checklist)";
        $stmt = $this->db->prepare($sql);
        $params = [
            ":id_user" => $itemDetail['id_user'],
            ":id_server" => $value->id_server,
            ":id_notiket" => $value->id_notiket,
            ":waktu" => $waktu,
            ":kondisi" => $itemDetail['kondisi'],
            ":keterangan" => $keterangan,
            ":foto" => $value->foto,
            ":sesi_checklist" => $sesiChecklist,  
        ];
        
        $res=$stmt->execute($params);
        if(!$res)
            $flag++;
    }
    if($flag==0)
        return $response->withJson(["status" => "success", "data" => "1"], 200);
    else
        return $response->withJson(["status" => "failed", "data" => "0"], 200);    
});
// $app->post('/simpanAmber/{id}', function(Request $request, Response $response, $args) use($container) {
//     $itemDetail = $request->getParsedBody();
//     $sesiChecklist=cekShift($container);
//     $flag=0;
//     foreach ($itemDetail['itemAmber'] as $key => $value) {
//         $keterangan=(empty($value['keterangan']) ? null : $value['keterangan']);
//         $waktu=date("Y-m-d H:i:s");
//         // simpan nama file ke database
//         $sql = "INSERT INTO dc_checklist (id_user,id_server,id_notiket,waktu,kondisi,keterangan,foto,sesi_checklist)
//         VALUES (:id_user,:id_server,:id_notiket,:waktu,:kondisi,:keterangan,:foto,:sesi_checklist)";
//         $stmt = $this->db->prepare($sql);
//         $params = [
//             ":id_user" => $itemDetail['id_user'],
//             ":id_server" => $value['id_server'],
//             ":id_notiket" => $value['id_notiket'],
//             ":waktu" => $waktu,
//             ":kondisi" => $value['kondisi'],
//             ":keterangan" => $keterangan,
//             ":foto" => $value['foto'],
//             ":sesi_checklist" => $sesiChecklist,  
//         ];
//         $res=$stmt->execute($params);
//         if(!$res){
//             $flag++;
//         }
//     }
//     if($flag==0){
//         return $response->withJson(["status" => "success", "data" => "1"], 200);
//     }else{
//         return $response->withJson(["status" => "failed", "data" => "0"], 200);
//     }   
    
// });
$app->get("/createtiket/{param}/", function (Request $request, Response $response, $args){
    $param = $args["param"];
    $sql = "SELECT A.id_notiket
    FROM dc_notiket A
    WHERE A.`id_notiket`=:idNoTiket AND (A.`status`=:status AND A.`no_tiket_insiden` IS NULL)";

    $stmt = $this->db->prepare($sql);
    
    $stmt->execute([":idNoTiket" => $param,
                    ":status" =>1]);
    
    $row = $stmt->fetch()['id_notiket'];
    
    if(empty($row)){
        return $this->renderer->render($response, "404.php", $args);
    }else{
        $args['ids'] = $param;
        return $this->renderer->render($response, "formTiket.php", $args);
    }
});
$app->post('/createtiket', function(Request $request, Response $response, $args) {
    $itemDetail = $request->getParsedBody();
    $idTiket=$itemDetail['id_notiket'];
    $noTiket=$itemDetail['noTiket'];
    $sql="UPDATE dc_notiket SET no_tiket_insiden=:noTiket WHERE id_notiket=:idTiket";
    $stmt = $this->db->prepare($sql);
    
    $res=$stmt->execute([":noTiket" => $noTiket,
                    ":idTiket" => $idTiket]);
    if($res){
        return $this->renderer->render($response, "terimakasih.php", $args);
    }else{
        $args['ids'] = $idTiket;
        return $this->renderer->render($response, "formTiket.php", $args);
    }
    
}); 
$app->post('/simpanAmberPertama/{id}', function(Request $request, Response $response, $args)use ($container) {
    
    $uploadedFiles = $request->getUploadedFiles();
    $itemDetail = $request->getParsedBody();
    
    $keterangan=(empty($itemDetail['keterangan']) ? null : $itemDetail['keterangan']);
    $waktu=date("Y-m-d H:i:s");
    
    $sesiChecklist=cekShift($container);

    // handle single input with single file upload
    $uploadedFile = $uploadedFiles['photo'];
    
    if ($uploadedFile->getError() === UPLOAD_ERR_OK) {
        $filename=null;
        $lastIdTiket=null;
        if($uploadedFile->getSize()>0){            
            $extension = pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION);            
            $filename = date("YmdHis").'.'.$extension;
            
            $directory = $this->get('settings')['upload_directory'];
            $uploadedFile->moveTo($directory . DIRECTORY_SEPARATOR . $filename);
        
            if($itemDetail['kondisi']==0){
                $sqlTiket = "INSERT INTO dc_notiket (status,iduser,nama_user)
                        VALUES (:status,:iduser,:nama_user)";
                $stmtTiket = $this->db->prepare($sqlTiket);
                $paramsTiket = [
                    ":status" => 1,  
                    ":iduser" => $itemDetail['id_user'],  
                    ":nama_user" => $itemDetail['nama_user'], 
                ];
                if($stmtTiket->execute($paramsTiket)){
                    $lastIdTiket = $this->db->lastInsertId('id_notiket');
                    sendEmail($container,$lastIdTiket,$itemDetail['id_server'],$filename,$itemDetail['id_user'],$keterangan);
                }
            }
        }
        // simpan nama file ke database
        $sql = "INSERT INTO dc_checklist (id_user,id_server,id_notiket,waktu,kondisi,keterangan,foto,sesi_checklist)
        VALUES (:id_user,:id_server,:id_notiket,:waktu,:kondisi,:keterangan,:foto,:sesi_checklist)";
        $stmt = $this->db->prepare($sql);
        $params = [
            ":id_user" => $itemDetail['id_user'],
            ":id_server" => $itemDetail['id_server'],
            ":id_notiket" => $lastIdTiket,
            ":waktu" => $waktu,
            ":kondisi" => $itemDetail['kondisi'],
            ":keterangan" => $keterangan,
            ":foto" => $filename,
            ":sesi_checklist" => $sesiChecklist,  
        ];
        if($stmt->execute($params)){
            return $response->withJson(["status" => "success", "data" => $lastIdTiket], 200);
        }
        return $response->withJson(["status" => "failed", "data" => "0"], 200);
    }
});
$app->post('/resolveAmber/{id}/{idServer}', function(Request $request, Response $response, $args)use($container) {
    $errNum=0;
    $idTiket=$args['id'];
    $idServer=$args['idServer'];
    $uploadedFiles = $request->getUploadedFiles();
        
    $itemDetail = $request->getParsedBody();
    $keterangan=(empty($itemDetail['keterangan']) ? null : $itemDetail['keterangan']);
    $waktu=date("Y-m-d H:i:s");
    $sesiChecklist=cekShift($container);
    // handle single input with single file upload
    $uploadedFile = $uploadedFiles['photo'];

    if ($uploadedFile->getError() === UPLOAD_ERR_OK) {
        $extension = pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION);            
            $filename = date("YmdHis").'.'.$extension;
            
            $directory = $this->get('settings')['upload_directory_bukti'];
            $uploadedFile->moveTo($directory . DIRECTORY_SEPARATOR . $filename);

            $sql="UPDATE dc_notiket SET status=:status,foto_bukti=:foto_bukti WHERE id_notiket=:idTiket";
            $stmt = $this->db->prepare($sql);
            
            $res=$stmt->execute([":status" => 2,
                            ":foto_bukti"=>$filename,
                            ":idTiket" => $idTiket]);
            if($res){
                $sqlCekTiket="SELECT A.`id_notiket`
                FROM dc_checklist A
                LEFT JOIN dc_notiket B ON B.`id_notiket`=A.`id_notiket`
                WHERE A.`id_server`=:id_server AND B.`status`=:status
                GROUP BY A.`id_notiket`";
                $stmtCekTiket = $this->db->prepare($sqlCekTiket);
                

                $resCekTiket=$stmtCekTiket->execute([":id_server" => $idServer,
                                ":status"=>1]);
                $jumTiket = $stmtCekTiket->rowCount();
                if($jumTiket == 0){
                    // simpan nama file ke database
                    $sqlInsert = "INSERT INTO dc_checklist (id_user,id_server,waktu,kondisi,keterangan,sesi_checklist)
                    VALUES (:id_user,:id_server,:waktu,:kondisi,:keterangan,:sesi_checklist)";
                    $stmtInsert = $this->db->prepare($sqlInsert);
                    $paramsInsert = [
                        ":id_user" => $itemDetail['id_user'],
                        ":id_server" => $itemDetail['id_server'],
                        ":waktu" => $waktu,
                        ":kondisi" => $itemDetail['kondisi'],
                        ":keterangan" => $keterangan,
                        ":sesi_checklist" => $sesiChecklist,  
                    ];
                    $resInsert=$stmtInsert->execute($paramsInsert);
                    if(!$resInsert){
                        $errNum++;
                    }
                }              
            }else{
                $errNum++;
            }
    }else{
        $errNum++;
    }
    
    if($errNum==0){
        return $response->withJson(["status" => "success", "jumTiket" => $jumTiket,"data" => "1"], 200);
    }else{
        $args['ids'] = $idTiket;
        return $response->withJson(["status" => "failed",  "jumTiket" => $jumTiket,"data" => "0"], 200);    
    }
});

$app->get("/cekAmberSebelumnya/{param}/", function (Request $request, Response $response, $args)use($container){
    $param = $args["param"];
    $lastKondisi=cekLastKondisi($container,$param);
    
    return $response->withJson(["status" => "success","statusCode" => "1", "data" => $lastKondisi], 200);
});
function cekAmber($app,$idServer){
    
    $sql = "SELECT A.`id_checklist`,A.`kondisi`,A.`sesi_checklist`,A.`foto`,A.`keterangan`,
    DATE_FORMAT(A.`waktu`,'%d-%m-%Y %H:%i:%s') AS waktu,A.id_notiket,B.`no_tiket_insiden`,
    B.nama_user,A.id_server
    FROM dc_checklist A
    INNER JOIN dc_notiket B ON B.`id_notiket`=A.`id_notiket`
    WHERE A.`id_server`=:idServer AND A.`kondisi`=:kondisi AND (B.`status`=:status OR A.`id_notiket` IS NULL)
    GROUP BY A.`id_notiket`
    ORDER BY A.`waktu` ASC
    -- LIMIT 1";
    
    //kalo mau detail banyak limit 1 hapus
    $stmt = $app->db->prepare($sql);
    
    $stmt->execute([":idServer" => $idServer,
                    ":kondisi" => 0,
                    ":status" =>1]);
    $Amber['Row'] = $stmt->rowCount();
    $Amber['Daftar'] = $stmt->fetchAll();
    
    return $Amber;
}
function getNoTiket($app,$idServer){
    $sql = " SELECT A.`id_notiket`,A.`no_tiket_insiden`,A.`pic_insiden`,A.`status`,
    B.`id_server`,A.`created_date`
    FROM dc_notiket A
    INNER JOIN dc_checklist B ON B.`id_notiket`=A.`id_notiket`
    WHERE A.`status`=:status AND B.`id_server`=:idServer
    GROUP BY A.`id_notiket`
    ORDER BY B.`waktu` ASC
    #LIMIT 1";
    $stmt = $app->db->prepare($sql);
    $stmt->execute([":status" => 1,
                    ":idServer" => $idServer]);
    $Tiket['No'] = $stmt->fetchAll();
    $Tiket['Row'] = $stmt->rowCount(); 
    return $Tiket;
   
}
function getData($app,$idServer){
    $sql = "SELECT A.`id_server`,A.`sn`,A.`status_colo`,A.`no_bmn`,
            B.`servername`,
            C.`pemilik`,A.foto,
            CONCAT(D.`u`,'-',(D.`u`+(B.`u_count`-1))) AS u
            FROM dc_server A
            LEFT JOIN dc_servermachine B ON B.`id_servermachine`=A.`id_servermachine`
            LEFT JOIN dc_pemilik C ON C.`id_pemilik`=A.`id_pemilik`
            LEFT JOIN dc_location D ON D.`id_server`=A.`id_server`
            WHERE A.`id_server`=:idServer";
    $stmt = $app->db->prepare($sql);
    $stmt->execute([":idServer" => $idServer]);
    $result = $stmt->fetch();
    return $result;
}


$app->get('/send_mail', function (Request $request, Response $response, $args)use ($container){
    
   sendEmail($container,'7','238','20190717162457.jpg','236',null);     
    
});
function sendEmail($app,$idTiket,$idServer,$filename,$idUser,$keterangan){
    $keterangan=(empty($keterangan)) ? 'amber' : $keterangan;
// function sendEmail($idTiket){
    $sql = "SELECT A.`id_server`,A.`sn`,A.`status_colo`,A.`no_bmn`,
    B.`servername`,
    C.`pemilik`,A.foto,E.`rak_name`,CONCAT(D.u,'-',(D.`u`+(F.`u_count`-1))) AS u
    FROM dc_server A
    LEFT JOIN dc_servermachine B ON B.`id_servermachine`=A.`id_servermachine`
    LEFT JOIN dc_pemilik C ON C.`id_pemilik`=A.`id_pemilik`
    LEFT JOIN dc_location D ON D.`id_server`=A.`id_server`
    LEFT JOIN dc_rak E ON E.`id_rak`=D.`id_rak`
    
    INNER JOIN dc_servermachine F ON F.`id_servermachine`=A.`id_servermachine`
    WHERE A.`id_server`=:idServer
    LIMIT 1";
    $stmt = $app->db->prepare($sql);
    $stmt->execute([":idServer" => $idServer]);
    $dataServer = $stmt->fetch();

    $sqlPetugas = "SELECT B.`nik`,B.`nama`
    FROM `user` A
    INNER JOIN karyawan B ON B.`idkaryawan`=A.`idkaryawan`
    WHERE A.`iduser`=:idUser
    LIMIT 1";
    $stmtPetugas = $app->db->prepare($sqlPetugas);
    $stmtPetugas->execute([":idUser" => $idUser]);
    $dataPetugas = $stmtPetugas->fetch();
    // $filename="1.jpg";
    // $dataServer=["servername" => 'IBM',
    //             "sn" => 'sn',
    //             "pemilik" => 'BC',
    //             "rak_name" => 'M-01'];
    //send email with php mailer
    $mail = new PHPMailer(true);
    try{
        $mail->SMTPDebug = 1;                               
        //Set PHPMailer to use SMTP.
        $mail->isSMTP();            
        //Set SMTP host name                          
        $mail->Host = "smtp.kemenkeu.go.id";
        //Set this to true if SMTP host requires authentication to send email
        $mail->SMTPAuth = false;                          
        //Provide username and password     
                          
        //If SMTP requires TLS encryption then set it
        // $mail->SMTPSecure = "tls";                           
        $mail->SMTPSecure = false;
        $mail->SMTPAutoTLS = false;
        //Set TCP port to connect to 
        $mail->Port = 25;                                   
        $mail->From = "alertnoc@kemenkeu.go.id";
        $mail->FromName = "Petugas Shift";

        // $mail->addAddress("gerardus.ordo@kemenkeu.go.id", "Bidang Optik");
        $mail->addAddress("servicedesk@kemenkeu.go.id", "Service Desk");
        $mail->addCC('ifpd.pusintek@kemenkeu.go.id');
        // $mail->addBCC('bcc@example.com');

        $mail->isHTML(true);

        $mail->Subject = "Tindak Lanjut Hasil Checklist - {$keterangan} - SN:{$dataServer['sn']} - {$dataPetugas['nama']}";
       
        $htmlBody = "<html>
                    <body>
                    <p>Yth. RR Service Desk,</p>
                    <p>Berikut kami sampaikan temuan alert warning pada perangkat:</p>
                    <table>
                        <tr>
                            <td>Nama</td>
                            <td>:</td>
                            <td>{$dataServer['servername']}</td>
                        </tr>
                        <tr>
                            <td>SN</td>
                            <td>:</td>
                            <td>{$dataServer['sn']}</td>
                        </tr>
                        <tr>
                            <td>BMN</td>
                            <td>:</td>
                            <td>{$dataServer['no_bmn']}</td>
                        </tr>
                        <tr>
                            <td>Unit</td>
                            <td>:</td>
                            <td>{$dataServer['pemilik']}</td>
                        </tr>
                        <tr>
                            <td>Letak</td>
                            <td>:</td>
                            <td>Rak {$dataServer['rak_name']} / {$dataServer['u']}</td>
                        </tr>
                        <tr>
                            <td>NIP Pelapor</td>
                            <td>:</td>
                            <td>{$dataPetugas['nik']}</td>
                        </tr>
                        <tr>
                            <td>Nama Pelapor</td>
                            <td>:</td>
                            <td>{$dataPetugas['nama']}</td>
                        </tr>
                        <tr>
                            <td>Keterangan</td>
                            <td>:</td>
                            <td>{$keterangan}</td>
                        </tr>
                    </table>
                    <img src='http://10.242.65.3/slim-api/public/uploads/ceklis/{$filename}' height='400px' width='500px'>
                    
                    <p>Harap bantuan rekan-rekan Service Desk Untuk menginputkan No Tiket pada link Berikut : 
                    <a href='http://10.242.65.3/slim-api/public/index.php/createtiket/{$idTiket}/?key=df4ca218445c38ed15d33a516fc248e93245a8d63a3b4cf8a8dcc4edf5654fe5' title='Aplikasi Ceklis'>Input Nomor Tiket</a>
                    </p>

                    <p> Demikian kami sampaikan atas perhatiannya kami ucapkan terima kasih​ .</p>";

        $mail->Body = $htmlBody;
    }
    catch (Exception $e) {
    $error = $mail->ErrorInfo;
    //return error here
    }
    if( $mail->Send() ) {
        return true;
    } else {
        return false;
    }
}

function cekLastKondisi($app,$idServer){
    $sql = "SELECT A.`kondisi`
            FROM dc_checklist A
            WHERE A.`id_server`=:idServer
            ORDER BY A.`waktu` DESC
            LIMIT 1";
    
    $stmt = $app->db->prepare($sql);
    $stmt->execute([":idServer" => $idServer]);
    $result = $stmt->fetch()['kondisi'];
    return $result;
}
// function cekLastTiket($app,$idServer){
//     $sql = "SELECT A.id_notiket
//             FROM dc_checklist A
//             WHERE A.`id_server`=:idServer
//             ORDER BY A.`waktu` DESC
//             LIMIT 1";
    
//     $stmt = $app->db->prepare($sql);
//     $stmt->execute([":idServer" => $idServer]);
//     $result = $stmt->fetch()['id_notiket'];
//     return $result;
// }
function cekLastTiket($app,$idServer){
    $sql = "SELECT A.`id_notiket`
        FROM dc_notiket A
        INNER JOIN dc_checklist B ON B.`id_notiket`=A.`id_notiket`
        WHERE A.`status`=:status AND B.`id_server`=:idServer
        GROUP BY A.`id_notiket`";
    
    $stmt = $app->db->prepare($sql);
    $stmt->execute([":status" => 1,
                    ":idServer" => $idServer]);
    $result = $stmt->fetchAll();
    return $result;
}
function updateTiket($app,$lastTiket){
    
    foreach ($lastTiket as $key => $value) {
        $flag=0;
        $sql="UPDATE dc_notiket SET status=:status WHERE id_notiket=:idNoTiket";    
        $stmt = $app->db->prepare($sql);
        $res=$stmt->execute([":status" => 2,
                    ":idNoTiket" => $value['id_notiket']]);
        if(!$res)
            $flag++;
    }
    if($flag==0)
        return true;
    else    
        return false;
}
$app->get('/cekEksisData', function (Request $request, Response $response, $args)use ($container){
    
    echo cekEksisData($container,'189',2);     
     
 });
function cekEksisData($app,$idServer,$sesi){
    $sql="SELECT A.`id_server`,A.`kondisi`
    FROM dc_checklist A
    WHERE A.`sesi_checklist`=:sesi AND DATE_FORMAT(A.`waktu`,'%Y-%m-%d')=DATE_FORMAT(NOW(),'%Y-%m-%d') AND A.id_server=:idServer
    ";
    $stmt = $app->db->prepare($sql);
    
    $res=$stmt->execute([":sesi" => $sesi,
                    ":idServer" => $idServer]);
    $jumEksis = $stmt->rowCount();
    if($jumEksis==0){
        return true;
    }else{
        return false;
    }
    
}

function updatePower($app,$idServer){
    $sql="UPDATE dc_server SET poweroff=:poweroff WHERE id_server=:idServer";
    $stmt = $app->db->prepare($sql);
    
    $res=$stmt->execute([":poweroff" => 0,
                    ":idServer" => $idServer]);
    if($res)
        return true;
    else    
        return false;
}

function cekShift($app){
    $sql = "SELECT A.id_shift
    FROM setting_shift A
    WHERE TIME(NOW()) BETWEEN A.`jam_awal` AND A.`jam_akhir`";

    $stmt = $app->db->prepare($sql);
    $stmt->execute();
    $result = $stmt->fetch()['id_shift'];
    return $result;
}
<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

include 'location.php';

$app->get('/', function (Request $request, Response $response, $args) {
    // Render index view
    return $this->view->render($response, 'index.latte');
})->setName('index');



$app->post('/test', function (Request $request, Response $response, $args) {
    //read POST data
    $input = $request->getParsedBody();

    //log
    $this->logger->info('Your name: ' . $input['person']);

    return $response->withHeader('Location', $this->router->pathFor('index'));
})->setName('redir');

/*Seznam lokací*/
$app->get('/locations', function (Request $request, Response $response, $args) {
	$stmt = $this->db->query('SELECT * FROM location ORDER BY country');
	$tplVars['location_list'] = $stmt->fetchall(); 
	return $this->view->render($response, 'locationList.latte', $tplVars);
})->setName('locations');

/* Zoznam vsech osob v DB */
$app->get('/persons', function (Request $request, Response $response, $args) {
	$stmt = $this->db->query('SELECT * FROM person ORDER BY first_name'); # toto vrati len DB objekt, nie vysledok!
	$tplVars['persons_list'] = $stmt->fetchall(); # [ ['id_person' => 1, 'first_name' => 'Alice' ... ], ['id_person' => 2, 'first_name' => 'Bob' ... ] . ]
	return $this->view->render($response, 'persons.latte', $tplVars);
})->setName('persons');


$app->get('/search', function (Request $request, Response $response, $args) {
	$queryParams = $request->getQueryParams(); # [kluc => hodnota]
	if(! empty($queryParams) ) {
		$stmt = $this->db->prepare("SELECT * FROM person WHERE lower(first_name) = lower(:fname) OR lower(last_name) = lower(:lname)");
		$stmt->bindParam(':fname', $queryParams['q']);
		$stmt->bindParam(':lname', $queryParams['q']);
		$stmt->execute();
		$tplVars['persons_list'] = $stmt->fetchall();
		return $this->view->render($response, 'persons.latte', $tplVars);
	}
})->setName('search');


/* nacitanie formularu */
$app->get('/person', function (Request $request, Response $response, $args) {
	$tplVars['formData'] = [
		'first_name' => '',
        'last_name' => '',
        'nickname' => '',
        'id_location' => null,
        'gender' => '',
        'height' => '',
        'birth_day' => '',
        'city' => '',
        'street_name' => '',
        'street_number' => '',
        'zip' => ''
	];
	return $this->view->render($response, 'newPerson.latte', $tplVars);
})->setName('newPerson');


/* spracovanie formu po odoslani */
$app->post('/person', function (Request $request, Response $response, $args) {
	$formData = $request->getParsedBody();
	$tplVars = [];
	if ( empty($formData['first_name']) || empty($formData['last_name']) || empty($formData['nickname']) ) {
		$tplVars['message'] = 'Please fill required fields';
	} else {
		try {
			$this->db->beginTransaction();
			if ( !empty($formData['street_name']) || !empty($formData['street_number']) || !empty($formData['city']) || !empty($formData['zip']) ) {
				## Osoba nema adresu (id_location NULL)
				$id_location = newLocation($this, $formData);
			}

			$stmt = $this->db->prepare("INSERT INTO person (nickname, first_name, last_name, id_location, birth_day, height, gender) VALUES (:nickname, :first_name, :last_name, :id_location, :birth_day, :height, :gender)");	
			$stmt->bindValue(':nickname', $formData['nickname']);
			$stmt->bindValue(':first_name', $formData['first_name']);
			$stmt->bindValue(':last_name', $formData['last_name']);
			$stmt->bindValue(':id_location', empty($formData['id_location']) ? null : $formData['id_location']);
			$stmt->bindValue(':gender', empty($formData['gender']) ? null : $formData['gender'] ) ;
			$stmt->bindValue(':birth_day', empty($formData['birth_day']) ? null : $formData['birth_day']);
			$stmt->bindValue(':height', empty($formData['height']) ? null : $formData['height']);
			$stmt->execute();
			$tplVars['message'] = 'Person succefully added';
			$this->db->commit();
		} catch (PDOexception $e) {
			$tplVars['message'] = 'Error occured, sorry jako';
			$this->logger->error($e->getMessage());
			$tplVars['formData'] = $formData;
			$this->db->rollback();
		}
	}
	return $this->view->render($response, 'newPerson.latte', $tplVars);
});


/* nacitanie formularu */
$app->get('/person/update', function (Request $request, Response $response, $args) {
	$params = $request->getQueryParams(); # $params = [id_person => 1232, firstname => aaa]
	if (! empty($params['id_person'])) {
		$stmt = $this->db->prepare('SELECT * FROM person 
									LEFT JOIN location USING (id_location) 
									WHERE id_person = :id_person');
		$stmt->bindValue(':id_person', $params['id_person']);
		$stmt->execute();
		$tplVars['formData'] = $stmt->fetch();
		if (empty($tplVars['formData'])) {
			exit('person not found');
		} else {
			return $this->view->render($response, 'updatePerson.latte', $tplVars);
		}
	}
})->setName('updatePerson');


$app->post('/person/update', function (Request $request, Response $response, $args) {
	$id_person = $request->getQueryParam('id_person');
	$formData = $request->getParsedBody();
	$tplVars = [];
	if ( empty($formData['first_name']) || empty($formData['last_name']) || empty($formData['nickname']) ) {
		$tplVars['message'] = 'Please fill required fields';
	} else {
		try {
			# Kontrolujeme ci bola aspon jedna cast adresy vyplnena
			if ( !empty($formData['street_name']) || !empty($formData['street_number']) || !empty($formData['city']) || !empty($formData['zip']) ) {

				$stmt = $this->db->prepare('SELECT id_location FROM person WHERE id_person = :id_person');
				$stmt->bindValue(':id_person', $id_person);
				$stmt->execute();
				$id_location = $stmt->fetch()['id_location']; # {'id_location' => 123}
				if ($id_location) {
					## Osoba ma adresu (id_location IS NOT NULL)
					editLocation($this, $id_location, $formData);
				} else {
					## Osoba nema adresu (id_location NULL)
					$id_location = newLocation($this, $formData);
				}
			}
			$stmt = $this->db->prepare("UPDATE person SET 
												first_name = :first_name,  
												last_name = :last_name,
												nickname = :nickname,
												birth_day = :birth_day,
												gender = :gender,
												height = :height,
												id_location = :id_location
										WHERE id_person = :id_person");
			$stmt->bindValue(':nickname', $formData['nickname']);
			$stmt->bindValue(':first_name', $formData['first_name']);
			$stmt->bindValue(':last_name', $formData['last_name']);
			$stmt->bindValue(':id_location',  $id_location ? $id_location : null);
			$stmt->bindValue(':gender', empty($formData['gender']) ? null : $formData['gender'] );
			$stmt->bindValue(':birth_day', empty($formData['birth_day']) ? null : $formData['birth_day']);
			$stmt->bindValue(':height', empty($formData['height']) ? null : $formData['height']);
			$stmt->bindValue(':id_person', $id_person);
			$stmt->execute();

		} catch (PDOexception $e) {
			$tplVars['message'] = 'Error occured, sorry jako';
			$this->logger->error($e->getMessage());
		}
	}
	$tplVars['formData'] = $formData;
	return $this->view->render($response, 'updatePerson.latte', $tplVars);
});


$app->post('/persons/delete', function (Request $request, Response $response, $args){
	$id_person = $request->getQueryParam('id_person');
	if (!empty($id_person)) {
		try{
			$stmt = $this->db->prepare('DELETE FROM person WHERE id_person = :id_person');
			$stmt->bindValue(':id_person', $id_person);
			$stmt->execute();
		} catch (PDOexception $e){
			$this->logger->error($e->getMessage());
			exit('error occured');
		}
	} else {
		exit('ID person is missing');
	}
	return $response->withHeader('Location', $this->router->pathFor('persons'));
})->setName('person_delete');

/*Zobraz kontakty*/
$app->get('/persons/contacts', function (Request $request, Response $response, $args){
	$params = $request->getQueryParams();
	$id_person = $params['id_person'];
	if (!empty($id_person)){
		try{
			$stmt = $this->db->prepare('SELECT id_contact,name,contact FROM contact c LEFT JOIN contact_type ct ON c.id_contact_type = ct.id_contact_type WHERE c.id_person = :id_person GROUP BY c.id_contact, ct.name');
			$stmt->bindValue(':id_person', $id_person);
			$stmt->execute();
		} catch (PDOexception $e){
			$this->logger->error($e->getMessage());
			exit('error occured');
		}
		$tplVars['id_person'] = $id_person;
		$tplVars['contact_list'] = $stmt->fetchall();
		return $this->view->render($response, 'contactList.latte', $tplVars);
	} else {
		exit('ID person is missing');
	}
	
})->setName('contactList');


/* formular pro edit kontaktu */
$app->get('/persons/contacts/update', function (Request $request, Response $response, $args) {
	$params = $request->getQueryParams();
	$stmt2 = $this->db->prepare('SELECT * FROM contact_type');
	$stmt2->execute();
    $tplVars['contact'] = $stmt2->fetchAll();
	$id_contact = $params['id_person'];
	if (! empty($params['id_person'])) {
		try{
		$stmt = $this->db->prepare('SELECT id_contact,name,contact,ct.id_contact_type as contact_type FROM contact c JOIN contact_type ct ON ct.id_contact_type = c.id_contact_type WHERE id_contact = :id_person GROUP BY id_contact, name, contact_type');
		$stmt->bindValue(':id_person', $params['id_person']);
		$stmt->execute();
		} catch (PDOexception $e){
			$this->logger->error($e->getMessage());
			exit('error occured');
		}
		$tplVars['id_person'] = $id_contact;
		$tplVars['formDatass'] = $stmt->fetch();
		return $this->view->render($response, 'editContact.latte', $tplVars);
	}
})->setName('updateContact');

/* Editovat kontakt*/
$app->post('/persons/contacts/update', function (Request $request, Response $response, $args) {
	$formData = $request->getParsedBody();
	$id_contact = $request->getQueryParam('id_person');
	$tplVars = [];
	if (empty($formData['name']) || empty($id_contact) || empty($formData['contact'])){
		$tplVars['message'] = 'Please fill required fields';
	}
	else {
		try {
		$this->db->beginTransaction();
		$stmt3 = $this->db->prepare('SELECT id_contact_type FROM contact_type WHERE name = :name');
		$stmt3->bindValue(':name', $formData['name'] );
		$stmt3->execute();
		$values = $stmt3->fetch();
		$id_contact_type = $values['id_contact_type'];
		$stmt = $this->db->prepare('UPDATE contact SET contact = :contact WHERE id_contact = :id_contact');
		$stmt->bindValue(':contact', $formData['contact']);
		$stmt->bindValue(':id_contact', $id_contact);
		$stmt->execute();
		$stmt2 = $this->db->prepare('UPDATE contact_type SET name = :name WHERE id_contact_type = :id_contact_type');
		$stmt2->bindValue(':name', $formData['name']);
		$stmt2->bindValue(':id_contact_type', $id_contact_type);
		$stmt2->execute();
		$this->db->commit();

		} catch (PDOexception $e){
			$tplVars['message'] = 'Error occured, sorry jako';
			$this->logger->error($e->getMessage());
			$tplVars['formData'] = $formData;
			$this->db->rollback();
			}
		}
	if (empty($formData['name'])) $tplVars['message'] = 'Lmao';
	$tplVars['formDatass'] = $formData;
	return $this->view->render($response, 'editContact.latte', $tplVars);
	});

/* smazat kontakt */

$app->post('/persons/contacts/delete', function (Request $request, Response $response, $args){
	$id_contact = $request->getQueryParam('id_person');
	if (!empty($id_contact)) {
		try{
			$stmt = $this->db->prepare('DELETE FROM contact WHERE id_contact = :id_contact');
			$stmt->bindValue(':id_contact', $id_contact);
			$stmt->execute();
		} catch (PDOexception $e){
			$this->logger->error($e->getMessage());
			exit('error occured');
		}
	} else {
		exit('ID person is missing');
	}
	return $response->withHeader('Location', $this->router->pathFor('contactList') . '?id_person=' . $id_contact);
})->setName('contact_delete');

/* novy kontakt formular */
$app->get('/person/newContact', function (Request $request, Response $response, $args) {
	$stmt = $this->db->prepare('SELECT * FROM contact_type');
	$id_person = $request->getQueryParam('id_person');
	if (empty($id_person)) exit ("ID nenalezeno");
    $stmt->execute();
    $tplVars['contact'] = $stmt->fetchAll();
    $tplVars['formDatass'] = [
		'name' => '',
        'contact' => '',
    	'id_person' => $id_person];
	return $this->view->render($response, 'newContact.latte', $tplVars);
})->setName('newContact');

 /* Pridat kontakt*/
$app->post('/person/newContact', function (Request $request, Response $response, $args) {
    $formData = $request->getParsedBody();
    $formData['id_person'] = $request->getParam('id_person');
    if (empty($formData['name'])) exit('juch');
    if (empty($formData['name']) || empty($formData['id_person']) || empty($formData['contact'])){
		exit ("jaaj");
	}
    try {
        
        $stmt2 = $this->db->prepare('SELECT id_contact_type FROM contact_type WHERE name = :name');
        $stmt2->bindValue(":name", $formData['name']);
        $stmt2->execute();
        $id = $stmt2->fetch();
        $stmt = $this->db->prepare('INSERT INTO contact (id_person, id_contact_type, contact) VALUES (:id_person, :id_contact_type, :contact)');
        $stmt->bindValue(":id_person", $formData['id_person']);
        $stmt->bindValue("id_contact_type", $id['id_contact_type']);
        $stmt->bindValue("contact", $formData['contact']);
        $stmt->execute();
        return $response->withHeader('Location', $this->router->pathFor('contactList') . '?id_person=' . $formData['id_person']);
    } catch (PDOException $exception) {
        $this->logger->error($exception->getMessage());
        exit("Nepovedlo se");
    }
    $tplVars['formData'] = $formData;
    return $this->view->render($response, 'newContact.latte', $tplVars);
});
/* Ukazat relations*/
$app->get('/relations', function (Request $request, Response $response, $args) {
    $params = $request->getQueryParams();
    try{
        $stmt = $this->db->query('SELECT * FROM relation r
                                  LEFT JOIN (SELECT id_person as id_person1, first_name as first_name_id1, last_name as last_name_id1 FROM person) 
                                                      as person1 ON r.id_person1 = person1.id_person1
                                  LEFT JOIN (SELECT id_person as id_person2, first_name as first_name_id2, last_name as last_name_id2 FROM person)
                                                      as person2 ON r.id_person2 = person2.id_person2
                                  LEFT JOIN relation_type rt on r.id_relation_type = rt.id_relation_type
                                  ORDER BY id_relation ');
        $stmt->execute();
        $tplVars['relation_list'] = $stmt->fetchAll();
    } catch (PDOException $e){
        $this->logger->info($e);
    }
    return $this->view->render($response, 'relationList.latte', $tplVars);
})->setName('relations');

/* Smazat relation*/
$app->post('/relation/delete', function (Request $request, Response $response, $args) {
    $params = $request->getQueryParams();
    try {
        $statement = $this->db->prepare("DELETE FROM relation WHERE id_relation=:id_relation");
        $statement->bindValue(":id_relation", $params['id_relation']);
        $statement->execute();
    }catch(PDOException $e){
        $this->logger->info($e);
    }

    return $response->withHeader('Location', $this->router->pathFor('relations'));
})->setName('relation_delete');

/* Editovat relation*/
$app->get('/relation/update', function (Request $request, Response $response, $args) {
    $params = $request->getQueryParams();
    if (!empty($params['id_relation'])) {
        try{
            $stmt = $this->db->prepare('SELECT * FROM relation r JOIN relation_type rt 
                                        on r.id_relation_type = rt.id_relation_type WHERE id_relation= :id_relation');
            $stmt->bindValue(':id_relation', $params['id_relation']);
            $stmt->execute();
            $description = $stmt->fetchall();
            $tplVars['relation'] = $description;
            $stmt2 = $this->db->query('SELECT * FROM relation_type ');
            $stmt2->execute();
            $tplVars['relation_list'] = $stmt2->fetchAll();
        }catch(PDOException $e){
            $this->logger->info($e);
            exit('Error');
        }
        return $this->view->render($response, 'relationEdit.latte', $tplVars);
    }
    exit("Chybka se vloudila");
})->setName('updateRelation');

$app->post('/relation/update', function (Request $request, Response $response, $args) {
    $formData = $request->getParsedBody();
    $id_relation = $request->getQueryParam('id_relation');
    if (empty($id_relation)) {
        exit('relation is missing');
    } else {
        try {
        	$stmt2 = $this->db->prepare('SELECT * FROM relation_type WHERE name = :name');
        	$stmt2->bindValue(':name', $formData['name']);
        	$stmt2->execute();
        	$relations_type = $stmt2->fetch();
            $stmt = $this->db->prepare('UPDATE relation SET description = :description, 
                id_relation_type = :id_relation_type WHERE id_relation = :id_relation');
            $stmt->bindValue(":id_relation_type", $relations_type['id_relation_type']);
            $stmt->bindValue(":description", $formData['description']);
            $stmt->bindValue(":id_relation", $id_relation);
            $stmt->execute();
        } catch (PDOException $e) {
            $tplVars['message'] = 'Upss';
            $this->logger->info($e);
        }
    }
    return $response->withHeader('Location', ($this->router->pathFor('updateRelation')).'?id_relation='.$id_relation);

})->setName('updateRelation');

/* Zobrazeni meetingu*/
$app->get('/meetings', function (Request $request, Response $response, $args) {
    $stmt = $this->db->query('SELECT * FROM meeting ORDER BY start');
    $tplVars['meeting_list'] = $stmt->fetchAll();
    return $this->view->render($response, 'meetingList.latte', $tplVars);
})->setName('meetings');

/* novy meeting*/
$app->get('/meetings/add', function (Request $request, Response $response, $args) {

    $tplVars['formData'] = [
        'start' => '',
        'id_meeting' => '',
        'description' => '',
        'id_location' => null,
        'city' => '',
        'street_name' => '',
        'street_number' => '',
        'zip' => ''
    ];
    return $this->view->render($response, 'newMeeting.latte', $tplVars);
})->setName('newMeeting');

$app->post('/meetings/add', function (Request $request, Response $response, $args) {
    $formData = $request->getParsedBody();
    if (empty($formData['start']) || empty($formData['description'])) {
        $tplVars['message'] = 'Fill required fields';
    } else {
        if ( !empty($formData['street_name']) || !empty($formData['street_number']) || !empty($formData['city']) || !empty($formData['zip']) ) {
				## Osoba nema adresu (id_location NULL)
				$id_location = newLocation($this, $formData);
			}
        try {
            $stmt = $this->db->prepare("INSERT INTO meeting (start, id_meeting, description, id_location)
									VALUES (:start, :id_meeting, :description, :id_location)");
            $stmt->bindValue(":start", $formData['start']);
            $stmt->bindValue(":id_meeting", $formData['id_meeting']);
            $stmt->bindValue(":description", $formData['description']);
            $stmt->bindValue(":id_location", $id_location);
            $stmt->execute();
            $tplVars['message'] = 'Meeting succesfully inserted';
        } catch (PDOException $e) {
            $tplVars['message'] = 'Sorry, error occured';
            $this->logger->error($e->getMessage());
        }
    }
    
   
    return $response->withHeader('Location', ($this->router->pathFor('meetings')));
    
    $tplVars['formData'] = $formData;
    return $this->view->render($response, 'newMeeting.latte', $tplVars);
});

/*Smazat meeting*/
$app->post('/meetings/delete', function (Request $request, Response $response, $args) {
    $params = $request->getQueryParams();
    if (empty($params['id_meeting'])) {
        exit('Meeting is missing');
    } else {
        try {
            $stmt = $this->db->prepare("DELETE FROM meeting WHERE id_meeting=:id_meeting");
            $stmt->bindValue(":id_meeting", $params['id_meeting']);
            $stmt->execute();
        } catch (PDOException $exception) {
            $this->logger->info($exception);
            exit('Error');
        }
    }
    return $response->withHeader('Location', $this->router->pathFor('meetings'));
})->setName('meeting_delete');

/* Osoby na meetingu*/
$app->get('/meetings/persons', function (Request $request, Response $response, $args) {
    $formData = $request->getParsedBody();
    $params = $request->getQueryParams();
    try {
        $stmt = $this->db->prepare("SELECT * FROM (person as p LEFT JOIN person_meeting as pm ON p.id_person = pm.id_person) JOIN meeting ON pm.id_meeting = meeting.id_meeting
									WHERE meeting.id_meeting = :id_meeting");
        $stmt->bindValue(":id_meeting", $params['id_meeting']);
        $stmt->execute();
        $tplVars['persons_list'] = $stmt->fetchAll();
    } catch (PDOException $e) {
        $this->logger->info($e);
    }
    
    $tplVars['id_meeting'] = $params['id_meeting'];
    
	return $this->view->render($response, 'person-meeting.latte', $tplVars);
})->setName('meeting-info');

/*Pridat osobu na meeting*/
$app->post('/meetings/addperson', function (Request $request, Response $response, $args) {
	$id_meeting = $request->getQueryParam('id_meeting');
	$formData = $request->getParsedBody();
	if(!empty($formData['q'])) {
		try{
			/*OR lower(last_name) = lower(:lname)*/
		$stmt = $this->db->prepare('SELECT id_person FROM person WHERE lower(first_name) = lower(:fname) OR lower(last_name) = lower(:lname)');
		$stmt->bindParam(':fname', $formData['q']);
		$stmt->bindParam(':lname', $formData['q']);
		$stmt->execute();
		$person = $stmt->fetch();	
		if (empty($person['id_person'])){
			exit('person not found');
		}
		$stmt3 = $this->db->prepare('SELECT * FROM person_meeting WHERE id_person = :id_person AND id_meeting = :id_meeting');
		$stmt3->bindValue(':id_person', $person['id_person']);
		$stmt3->bindValue(':id_meeting', $id_meeting);
		$stmt3->execute();
		$test = $stmt3->fetchall();
		if (!empty($test)) exit('Person has already joined the meeting');
		$stmt2 = $this->db->prepare("INSERT INTO person_meeting (id_person, id_meeting)							
			VALUES (:id_person, :id_meeting)");
		$stmt2->bindValue(':id_person', $person['id_person']);
		$stmt2->bindValue(':id_meeting', $id_meeting);
		$stmt2->execute();
    } catch (PDOException $e){
    	$this->logger->info($e);
    	exit('Error');
    }
		
	}
	return $response->withHeader('Location', ($this->router->pathFor('meeting-info')).'?id_meeting='.$id_meeting);
})->setName('meeting_addPerson');


/* info o meetingu*/
$app->get('/meeting/info', function (Request $request, Response $response, $args){
    $id_meeting = $request->getQueryParam('id_meeting');
    $stmt = $this->db->prepare("SELECT * FROM meeting LEFT JOIN location ON (meeting.id_location = location.id_location) 
                                         WHERE id_meeting = :id_meeting");
    $stmt->bindValue(":id_meeting", $id_meeting);
    $stmt->execute();
    $tplVars['meeting_info'] = $stmt->fetch();

    return $this->view->render($response, 'meetingInfo.latte', $tplVars);
})->setName('meetingInfo');

/* Editovat meeting */
$app->get('/meeting/edit', function (Request $request, Response $response, $args) {
    $id_meeting = $request->getQueryParam('id_meeting');
    if (empty($id_meeting)) {
        exit('id meeting is missing');
    } else {
        $stmt = $this->db->prepare("SELECT * FROM meeting LEFT JOIN location ON (meeting.id_location = location.id_location) WHERE id_meeting = :id_meeting");
        $stmt->bindValue(":id_meeting", $id_meeting);
        $stmt->execute();
        $tplVars['formData'] = $stmt->fetch();
        if (empty($tplVars['formData'])) {
            exit('meeting not found');
        } else {
            return $this->view->render($response,'editMeeting.latte', $tplVars);
        }
    }
})->setName('editMeeting');


$app->post('/meeting/edit', function (Request $request, Response $response, $args) {
    $id_meeting = $request->getQueryParam('id_meeting');
    $params = $request->getParsedBody();
    try {
    	if(empty($params['description'])) exit('lmao');
        $stmt = $this->db->prepare("UPDATE meeting SET start = :start, id_meeting = :id_meeting,
                  description = :description where id_meeting = :id_meeting;");
        $stmt->bindValue(":start", $params['start']);
        $stmt->bindValue(":id_meeting", $id_meeting);
        $stmt->bindValue(":description", $params['description']);
        $stmt->execute();
    } catch (PDOException $exception) {
        $tplVars['message'] = 'Error ocured';
        $this->logger->error($exception->getMessage());
    }
    return $response->withHeader('Location', $this->router->pathFor('meetings'));
});


/* New relation*/
$app->get('/relation/new', function (Request $request, Response $response, $args) {
        try{
            $stmt = $this->db->prepare('SELECT * FROM relation_type');
            $stmt->execute();
            $tplVars['relation_list'] = $stmt->fetchAll();
        }catch(PDOException $e){
            $this->logger->info($e);
            exit('Error');
        }
        return $this->view->render($response, 'newRelation.latte', $tplVars);
})->setName('updateRelation');



$app->post('/relation/new', function (Request $request, Response $response, $args) {
    $formData = $request->getParsedBody();
    if(empty($formData)) exit('Error ocured');
        try {
			$stmt4 = $this->db->prepare('SELECT * FROM relation_type WHERE name = :name');
        	$stmt4->bindValue(':name', $formData['name']);
        	$stmt4->execute();
        	$name = $stmt4->fetch();
        	if(empty($name['id_relation_type'])) exit('Error ocured');
        	$stmt = $this->db->prepare('SELECT id_person FROM person WHERE lower(first_name) = lower(:fname) OR lower(last_name) = lower(:lname)');
			$stmt->bindParam(':fname', $formData['first_person']);
			$stmt->bindParam(':lname', $formData['first_person']);
        	$stmt->execute();
        	$first = $stmt->fetch();
        	if (empty($first['id_person'])) exit('First  person not found');
        	$stmt2 = $this->db->prepare('SELECT id_person FROM person WHERE lower(first_name) = lower(:fname) OR lower(last_name) = lower(:lname)');
			$stmt2->bindParam(':fname', $formData['second_person']);
			$stmt2->bindParam(':lname', $formData['second_person']);
        	$stmt2->execute();
        	$second = $stmt2->fetch();
        	if (empty($second['id_person'])) exit('Second person not found');
        	$stmt3 = $this->db->prepare('INSERT INTO relation (id_person1, id_person2,  id_relation_type, description) VALUES (:id_person1, :id_person2, :id_contact_type, :description)');
        	$stmt3->bindValue(':id_person1', $first['id_person']);
			$stmt3->bindValue(':id_person2', $second['id_person']);
			$stmt3->bindValue(':id_contact_type', $name['id_relation_type']);
			$stmt3->bindValue(':description', $formData['description']);
			$stmtTEST = $this->db->prepare('SELECT * FROM contact WHERE id_person1 = :id_person1 AND id_person2 = :id_person2 AND id_relation_type = :id_relation_type');
			$stmtTEST->bindValue(':id_person1', $first['id_person']);
			$stmtTEST->bindValue(':id_person2', $second['id_person']);
			$stmtTEST->bindValue(':id_contact_type', $name['id_relation_type']);
			$stmtTEST->execute();
			$test = $stmtTEST->fetchall();
			if(empty($test)) $stmt3->execute();
			else exit('Relation is already going on');
        } catch (PDOException $e) {
            $tplVars['message'] = 'Upss';
            $this->logger->info($e);
            exit('Error ocured');
        }
    
    return $response->withHeader('Location', ($this->router->pathFor('relations')));
	});
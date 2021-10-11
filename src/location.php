<?php

/* Edituje existujuci zaznam v tabulke location */
function editLocation($app, $id_location, $formData) {
	$stmt = $app->db->prepare('UPDATE location 
							   SET 
						   		street_name = :street_name, 
						   		street_number = :street_number, 
						   		city = :city, 
						   		zip = :zip
							   WHERE id_location = :id_location');
	$stmt->bindValue(':street_name', empty($formData['street_name']) ? null : $formData['street_name']  );
	$stmt->bindValue(':street_number', empty($formData['street_number']) ? null : $formData['street_number']  );
	$stmt->bindValue(':zip', empty($formData['zip']) ? null : $formData['zip']  );
	$stmt->bindValue(':city', empty($formData['city']) ? null : $formData['city']  );
	$stmt->bindValue(':id_location', $id_location);
	$stmt->execute();
	return True;
}



function newLocation($app, $formData) {
	$stmt = $app->db->prepare('INSERT INTO location (street_name, street_number, zip, city) 
								VALUES (:street_name, :street_number, :zip, :city) RETURNING id_location');
	$stmt->bindValue(':street_name', empty($formData['street_name']) ? null : $formData['street_name']  );
	$stmt->bindValue(':street_number', empty($formData['street_number']) ? null : $formData['street_number']  );
	$stmt->bindValue(':zip', empty($formData['zip']) ? null : $formData['zip']  );
	$stmt->bindValue(':city', empty($formData['city']) ? null : $formData['city']  );
	$stmt->execute();
	
	$id_location = $stmt->fetch()['id_location'];
	return $id_location;
}
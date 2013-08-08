<?php

Director::addRules(100, array(
	EwayPayment_Handler::$URLSegment . '/$Action/$ID' => 'EwayPayment_Handler'
));
//===================---------------- START eway payment MODULE ----------------===================
//===================---------------- END eway payment MODULE ----------------===================

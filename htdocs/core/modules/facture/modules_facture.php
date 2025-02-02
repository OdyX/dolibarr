<?php
/* Copyright (C) 2003-2005 Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2011 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2004      Eric Seigne          <eric.seigne@ryxeo.com>
 * Copyright (C) 2005-2012 Regis Houssin        <regis.houssin@inodbox.com>
 * Copyright (C) 2014      Marcos García        <marcosgdf@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * or see https://www.gnu.org/
 */

/**
 *	\file       htdocs/core/modules/facture/modules_facture.php
 *	\ingroup    facture
 *	\brief      File that contains parent class for invoices models
 *              and parent class for invoices numbering models
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commondocgenerator.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php'; // Required because used in classes that inherit

use \Sprain\SwissQrBill;

/**
 *	Parent class of invoice document generators
 */
abstract class ModelePDFFactures extends CommonDocGenerator
{
	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	public $posxpicture;
	public $posxtva;
	public $posxup;
	public $posxqty;
	public $posxunit;
	public $posxdesc;
	public $posxdiscount;
	public $postotalht;

	public $tva;
	public $tva_array;
	public $localtax1;
	public $localtax2;

	public $atleastonediscount = 0;
	public $atleastoneratenotnull = 0;

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 *  Return list of active generation modules
	 *
	 *  @param	DoliDB	$db     			Database handler
	 *  @param  integer	$maxfilenamelength  Max length of value to show
	 *  @return	array						List of templates
	 */
	public static function liste_modeles($db, $maxfilenamelength = 0)
	{
		// phpcs:enable
		$type = 'invoice';
		$list = array();

		include_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
		$list = getListOfModels($db, $type, $maxfilenamelength);

		return $list;
	}

	/**
	 * Get the SwissQR object, including validation
	 *
	 * @param Facture 		$object  	Invoice object
	 * @param Translate 	$langs 		Translation object
	 * @return SwissQrBill|bool 		The valid SwissQR object, or false
	 */
	private function getSwissQrBill(Facture $object, Translate $langs)
	{
		if (getDolGlobalString('INVOICE_ADD_SWISS_QR_CODE') != 'bottom') {
			return false;
		}

		if ($object->mode_reglement_code != 'VIR') {
			$this->error = $langs->transnoentities("SwissQrOnlyVIR");
			return false;
		}

		if (empty($object->fk_account)) {
			$this->error = 'Bank account must be defined to use this experimental feature';
			return false;
		}

		require_once DOL_DOCUMENT_ROOT.'/includes/sprain/swiss-qr-bill/autoload.php';

		// Create a new instance of SwissQrBill, containing default headers with fixed values
		$qrBill = SwissQrBill\QrBill::create();

		// First, check creditor address
		$address = SwissQrBill\DataGroup\Element\CombinedAddress::create(
			$this->emetteur->name,
			$this->emetteur->address,
			$this->emetteur->zip . " " . $this->emetteur->town,
			$this->emetteur->country_code
		);
		if (!$address->isValid()) {
			$this->error = $langs->transnoentities("SwissQrCreditorAddressInvalid", (string) $address->getViolations());
			return false;
		}
		$qrBill->setCreditor($address);

		// Get IBAN from account.
		$account = new Account($this->db);
		$account->fetch($object->fk_account);

		$creditorInformation = SwissQrBill\DataGroup\Element\CreditorInformation::create($account->iban);
		if (!$creditorInformation->isValid()) {
			$this->error = $langs->transnoentities("SwissQrCreditorInformationInvalid", $account->iban, (string) $creditorInformation->getViolations());
			return false;
		}
		$qrBill->setCreditorInformation($creditorInformation);

		if ($creditorInformation->containsQrIban()) {
			$this->error = $langs->transnoentities("SwissQrIbanNotImplementedYet", $account->iban);
			return false;
		}

		// Add payment reference CLASSIC-IBAN
		// This is what you will need to identify incoming payments.
		$qrBill->setPaymentReference(
			SwissQrBill\DataGroup\Element\PaymentReference::create(
				SwissQrBill\DataGroup\Element\PaymentReference::TYPE_NON
			)
		);

		// Add payment amount, with currency
		$pai = SwissQrBill\DataGroup\Element\PaymentAmountInformation::create($object->multicurrency_code, $object->total_ttc);
		if (!$pai->isValid()) {
			$this->error = $langs->transnoentities("SwissQrPaymentInformationInvalid", $object->total_ttc, (string) $pai->getViolations());
			return false;
		}
		$qrBill->setPaymentAmountInformation($pai);

		// Add some human-readable information about what the bill is for.
		$qrBill->setAdditionalInformation(
			SwissQrBill\DataGroup\Element\AdditionalInformation::create(
				$object->ref
			)
		);

		// Check debtor address; We _know_ zip&town have to be filled, so skip that if unfilled.
		if (!empty($object->thirdparty->zip) && !empty($object->thirdparty->town)) {
			$address = SwissQrBill\DataGroup\Element\CombinedAddress::create(
				$object->thirdparty->name,
				$object->thirdparty->address,
				$object->thirdparty->zip . " " . $object->thirdparty->town,
				$object->thirdparty->country_code
			);
			if (!$address->isValid()) {
				$this->error = $langs->transnoentities("SwissQrDebitorAddressInvalid", (string) $address->getViolations());
				return false;
			}
			$qrBill->setUltimateDebtor($address);
		}

		return $qrBill;
	}

	/**
	 * Get the height for bottom-page QR invoice in mm, depending on the page number.
	 *
	 * @param int       $pagenbr Page number
	 * @param Facture   $object  Invoice object
	 * @param Translate $langs   Translation object
	 * @return int      Height in mm of the bottom-page QR invoice. Can be zero if not on right page; not enabled
	 */
	protected function getHeightForQRInvoice(int $pagenbr, \Facture $object, \Translate $langs) : int
	{
		if (getDolGlobalString('INVOICE_ADD_SWISS_QR_CODE') == 'bottom') {
			// Keep it, to reset it after QRinvoice getter
			$error = $this->error;

			if (!$this->getSwissQrBill($object, $langs)) {
				// Reset error to previous one if exists
				if (!empty($error)) {
					$this->error = $error;
				}
				return 0;
			}
			// SWIFT's requirementis 105, but we get more room with 100 and the page number is in a nice place.
			return $pagenbr == 1 ? 100 : 0;
		}

		return 0;
	}

	/**
	 * Add SwissQR invoice at bottom of page 1
	 *
	 * @param TCPDF     $pdf     TCPDF object
	 * @param Facture   $object  Invoice object
	 * @param Translate $langs   Translation object
	 * @return bool for success
	 */
	public function addBottomQRInvoice(\TCPDF $pdf, \Facture $object, \Translate $langs) : bool
	{
		if (!($qrBill = $this->getSwissQrBill($object, $langs))) {
			return false;
		}

		try {
			$pdf->startTransaction();

			$pdf->setPage(1);
			$pdf->SetTextColor(0, 0, 0);
			$output = new SwissQrBill\PaymentPart\Output\TcPdfOutput\TcPdfOutput($qrBill, in_array($langs->shortlang, ['de', 'fr', 'it']) ? $langs->shortlang : 'en', $pdf);
			$output->setPrintable(false)->getPaymentPart();
		} catch (Exception $e) {
			$pdf->rollbackTransaction(true);
			return false;
		}
		return true;
	}
}

/**
 *  Parent class of invoice reference numbering templates
 */
abstract class ModeleNumRefFactures
{
	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * Return if a module can be used or not
	 *
	 * @return	boolean     true if module can be used
	 */
	public function isEnabled()
	{
		return true;
	}

	/**
	 * Returns the default description of the numbering pattern
	 *
	 * @return    string      Descriptive text
	 */
	public function info()
	{
		global $langs;
		$langs->load("bills");
		return $langs->trans("NoDescription");
	}

	/**
	 * Return an example of numbering
	 *
	 * @return	string      Example
	 */
	public function getExample()
	{
		global $langs;
		$langs->load("bills");
		return $langs->trans("NoExample");
	}

	/**
	 *  Checks if the numbers already in the database do not
	 *  cause conflicts that would prevent this numbering working.
	 *
	 * @return	boolean     false if conflict, true if ok
	 */
	public function canBeActivated()
	{
		return true;
	}

	/**
	 * Renvoi prochaine valeur attribuee
	 *
	 * @param	Societe		$objsoc		Objet societe
	 * @param   Facture		$invoice	Objet facture
	 * @param   string		$mode       'next' for next value or 'last' for last value
	 * @return  string      			Value
	 */
	public function getNextValue($objsoc, $invoice, $mode = 'next')
	{
		global $langs;
		return $langs->trans("NotAvailable");
	}

	/**
	 * Renvoi version du modele de numerotation
	 *
	 * @return    string      Valeur
	 */
	public function getVersion()
	{
		global $langs;
		$langs->load("admin");

		if ($this->version == 'development') {
			return $langs->trans("VersionDevelopment");
		} elseif ($this->version == 'experimental') {
			return $langs->trans("VersionExperimental");
		} elseif ($this->version == 'dolibarr') {
			return DOL_VERSION;
		} elseif ($this->version) {
			return $this->version;
		} else {
			return $langs->trans("NotAvailable");
		}
	}
}

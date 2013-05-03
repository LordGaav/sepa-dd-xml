<?php

/**
 * @license GNU Lesser General Public License v3.0
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Lesser Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *  GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 * 
 * Inspired by: https://github.com/digitick/php-sepa-xml/
 *          (Jérémy Cambon, Ianaré Sévi and Vincent MOMIN)       
 * 
 * @author Robin de Vries <robin@celp.nl>
 * @version 2013-05-03  
 */


class SepaDirectDebitFile
{

    const INITIAL_STRING = '<?xml version="1.0" encoding="utf-8"?><Document xsi:schemaLocation="urn:iso:std:iso:20022:tech:xsd:pain.008.001.02 pain.008.001.02.xsd" xmlns="urn:iso:std:iso:20022:tech:xsd:pain.008.001.02" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"></Document>';


    public $messageIdentification;
    public $IBAN;
    public $CreditorSchemeId;

    protected $numberOfTransactions = 0;
    protected $xml;
    protected $transactions;
    
    public function __construct()
    {
        $this->xml = simplexml_load_string(self::INITIAL_STRING);
        $this->xml->addChild('CstmrDrctDbtInitn');
    }

    /**
     * Return the XML string.
     * @return string
     */
    public function asXML()
    {
        $this->generateXml();
        return $this->xml->asXML();
    }
    
    /**
     * Output the XML string to the screen.
     */
    public function outputXML()
    {
        $this->generateXml();
        header('Content-type: text/xml');
        echo $this->xml->asXML();
    }
    
    /**
     * Download the XML string into XML File
     */
    public function downloadXML()
    {
        $this->generateXml();
        header("Content-type: text/xml");
        header('Content-disposition: attachment; filename=sepa_' . date('dmY-His') . '.xml');
        echo $this->xml->asXML();
        exit();
    }

    /**
     * Generate the XML structure.
     */
    protected function generateXml()
    {
        $datetime = new DateTime();
        $creationDateTime = $datetime->format('Y-m-d\TH:i:s');
        $requestedExecutionDate = $datetime->format('Y-m-d');
        

        /* Groupheader */
        $GroupHeader = $this->xml->CstmrDrctDbtInitn->addChild('GrpHdr'); /* ISO: 1.0 */
        $GroupHeader->addChild('MsgId', $this->messageIdentification); /* ISO: 1.1 */
        $GroupHeader->addChild('CreDtTm', $creationDateTime); /* ISO: 1.2 */ 
        $GroupHeader->addChild('NbOfTxs', $this->numberOfTransactions); /* ISO: 1.6 */
        $GroupHeader->addChild('InitgPty')->addChild('Nm', $this->initiatingPartyName); /* ISO: 1.8 */
        
        /* Payment Information */
        $PaymentInformation = $this->xml->CstmrDrctDbtInitn->addChild('PmtInf'); /* ISO: 2.0 */
        $PaymentInformation->addChild('PmtInfId', $this->paymentInfoId); /* ISO: 2.1 */
        $PaymentInformation->addChild('PmtMtd', "DD"); /* ISO: 2.2 */

        $PaymentInformation->addChild('ReqdColltnDt', $requestedExecutionDate);  /* ISO: 2.18 */
        $PaymentInformation->addChild('Cdtr')->addChild('Nm', $this->initiatingPartyName); /* ISO: 2.19*/
        /* Creditor Account */
        $CreditorAccount = $PaymentInformation->addChild('CdtrAcct'); /* ISO: 2.20 */
        $CreditorAccount->addChild('Id')->addChild('IBAN',$this->IBAN);
       
        $PaymentInformation->addChild('CdtrAgt')->addChild('FinInstnId'); /* ISO: 2.21 */
        /* ISO: 2.27 */
        $PaymentInformation->addChild('CdtrSchmeId')->addChild('Id')->addChild('PrvtId')->addChild('Othr')->addChild('Id',$this->creditorid($this->kvk));
        $PaymentInformation->CdtrSchmeId->Id->PrvtId->Othr->addChild('SchmeNm')->addChild('Prtry','SEPA');
        
        /* Transactions */
        foreach($this->transactions as $transaction)
        {
            $TransactionInformation = $PaymentInformation->addChild('DrctDbtTxInf'); /* ISO: 2.28 */
            $TransactionInformation->addChild('PmtId')->addChild('EndToEndId',$transaction['end_to_end']); /* ISO: 2.29, 2.31 */
            $TransactionInformation->addChild('InstdAmt',$this->floatToCurrency($transaction['amount'])); /* ISO: 2.44 */
            $TransactionInformation->InstdAmt->addAttribute('Ccy','EUR');
            
            $TransactionInformation->addChild('DrctDbtTx')->addChild('MndtRltdInf'); /* ISO: 2.46, 2.47 */
            $TransactionInformation->DrctDbtTx->MndtRltdInf->addChild('MndtId',$transaction['ean']); /* ISO: 2.48 */
            $TransactionInformation->DrctDbtTx->MndtRltdInf->addChild('DtOfSgntr',$transaction['signature_date']); /* ISO: 2.49 */
            
            $TransactionInformation->addChild('DbtrAgt')->addChild('FinInstnId');
            
            $TransactionInformation->addChild('Dbtr')->addChild('Nm',$transaction['consumername']); /* ISO: 2.72 */
            $TransactionInformation->addChild('DbtrAcct')->addChild('Id')->addChild('IBAN',$transaction['consumeraccount']); /* ISO: 2.73 */
            $TransactionInformation->addChild('RmtInf')->addChild('Ustrd',substr($transaction['text'],0,140));
            
        }
        
    }
    
    /**
     * Add a transaction to the list of transactions
     */
    public function addTransaction($transaction)
    {
        $this->transactions[] = $transaction;
        $this->numberOfTransactions++;
        
    }
    
    /**
     * Format an float as a monetary value.
     */    
    protected function floatToCurrency($amount)
    {
        return sprintf("%01.2f", $amount);
    }
    
    /**
     * Calculate the Creditor Identifier.
     * As described in attachment B, in the format description of the Rabobank.
     */
    protected function creditorid($kvk)
    {
        return 'NL'.(98 - ($kvk.'0000'.'232100') % 97) . 'ZZZ' . $kvk . '0000';
        
    }
}

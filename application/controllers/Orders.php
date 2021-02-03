<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Orders extends Admin_Controller
{
	public function __construct()
	{
		parent::__construct();

		$this->not_logged_in();

		$this->data['page_title'] = 'Đơn hàng';

		$this->load->model('model_orders');
		$this->load->model('model_products');
		$this->load->model('model_company');
	}

	/* 
	* It only redirects to the manage order page
	*/
	public function index()
	{
		if (!in_array('viewOrder', $this->permission)) {
			redirect('dashboard', 'refresh');
		}

		$this->data['page_title'] = 'Quản lý đơn hàng';
		$this->render_template('orders/index', $this->data);
	}

	function convert_number_to_words($number)
	{
		$hyphen      = ' ';
		$conjunction = '  ';
		$separator   = ' ';
		$negative    = 'âm ';
		$decimal     = ' phẩy ';
		$dictionary  = array(
			0                   => 'không',
			1                   => 'một',
			2                   => 'hai',
			3                   => 'ba',
			4                   => 'bốn',
			5                   => 'năm',
			6                   => 'sáu',
			7                   => 'bảy',
			8                   => 'tám',
			9                   => 'chín',
			10                  => 'mười',
			11                  => 'mười một',
			12                  => 'mười hai',
			13                  => 'mười ba',
			14                  => 'mười bốn',
			15                  => 'mười năm',
			16                  => 'mười sáu',
			17                  => 'mười bảy',
			18                  => 'mười tám',
			19                  => 'mười chín',
			20                  => 'hai mươi',
			30                  => 'ba mươi',
			40                  => 'bốn mươi',
			50                  => 'năm mươi',
			60                  => 'sáu mươi',
			70                  => 'bảy mươi',
			80                  => 'tám mươi',
			90                  => 'chín mươi',
			100                 => 'trăm',
			1000                => 'nghìn',
			1000000             => 'triệu',
			1000000000          => 'tỷ',
			1000000000000       => 'nghìn tỷ',
			1000000000000000    => 'nghìn triệu triệu',
			1000000000000000000 => 'tỷ tỷ'
		);
		if (!is_numeric($number)) {
			return false;
		}
		if (($number >= 0 && (int) $number < 0) || (int) $number < 0 - PHP_INT_MAX) {
			// overflow
			trigger_error(
				'convert_number_to_words only accepts numbers between -' . PHP_INT_MAX . ' and ' . PHP_INT_MAX,
				E_USER_WARNING
			);
			return false;
		}
		if ($number < 0) {
			return $negative . $this->convert_number_to_words(abs($number));
		}
		$string = $fraction = null;
		if (strpos($number, '.') !== false) {
			list($number, $fraction) = explode('.', $number);
		}
		switch (true) {
			case $number < 21:
				$string = $dictionary[$number];
				break;
			case $number < 100:
				$tens   = ((int) ($number / 10)) * 10;
				$units  = $number % 10;
				$string = $dictionary[$tens];
				if ($units) {
					$string .= $hyphen . $dictionary[$units];
				}
				break;
			case $number < 1000:
				$hundreds  = $number / 100;
				$remainder = $number % 100;
				$string = $dictionary[$hundreds] . ' ' . $dictionary[100];
				if ($remainder) {
					$string .= $conjunction . $this->convert_number_to_words($remainder);
				}
				break;
			default:
				$baseUnit = pow(1000, floor(log($number, 1000)));
				$numBaseUnits = (int) ($number / $baseUnit);
				$remainder = $number % $baseUnit;
				$string = $this->convert_number_to_words($numBaseUnits) . ' ' . $dictionary[$baseUnit];
				if ($remainder) {
					$string .= $remainder < 100 ? $conjunction : $separator;
					$string .= $this->convert_number_to_words($remainder);
				}
				break;
		}
		if (null !== $fraction && is_numeric($fraction)) {
			$string .= $decimal;
			$words = array();
			foreach (str_split((string) $fraction) as $number) {
				$words[] = $dictionary[$number];
			}
			$string .= implode(' ', $words);
		}
		return $string;
	}

	/*
	* Fetches the orders data from the orders table 
	* this function is called from the datatable ajax function
	*/
	public function fetchOrdersData()
	{
		$result = array('data' => array());

		$data = $this->model_orders->getOrdersData();

		foreach ($data as $key => $value) {

			$count_total_item = $this->model_orders->countOrderItem($value['id']);
			$date = date('d-m-Y', $value['date_time']);
			$time = date('h:i a', $value['date_time']);

			$date_time = $date . ' ' . $time;

			// button
			$buttons = '';

			if (in_array('viewOrder', $this->permission)) {
				$buttons .= '<a target="__blank" href="' . base_url('orders/printDiv/' . $value['id']) . '" class="btn btn-default"><i class="fa fa-print"></i></a>';
			}

			if (in_array('updateOrder', $this->permission)) {
				$buttons .= ' <a href="' . base_url('orders/update/' . $value['id']) . '" class="btn btn-default"><i class="fa fa-pencil"></i></a>';
			}

			if (in_array('deleteOrder', $this->permission)) {
				$buttons .= ' <button type="button" class="btn btn-default" onclick="removeFunc(' . $value['id'] . ')" data-toggle="modal" data-target="#removeModal"><i class="fa fa-trash"></i></button>';
			}

			if ($value['paid_status'] == 1) {
				$paid_status = '<span class="label label-success">Đã thanh toán</span>';
			} else {
				$paid_status = '<span class="label label-warning">Chưa thanh toán</span>';
			}

			$result['data'][$key] = array(
				$value['bill_no'],
				$value['customer_name'],
				$value['customer_phone'],
				$date_time,
				$count_total_item,
				$value['net_amount'],
				$paid_status,
				$buttons
			);
		} // /foreach

		echo json_encode($result);
	}

	/*
	* If the validation is not valid, then it redirects to the create page.
	* If the validation for each input field is valid then it inserts the data into the database 
	* and it stores the operation message into the session flashdata and display on the manage group page
	*/
	public function create()
	{
		if (!in_array('createOrder', $this->permission)) {
			redirect('dashboard', 'refresh');
		}

		$this->data['page_title'] = 'Thêm đơn hàng';

		$this->form_validation->set_rules('product[]', 'Product name', 'trim|required');


		if ($this->form_validation->run() == TRUE) {

			$order_id = $this->model_orders->create();

			if ($order_id) {
				$this->session->set_flashdata('success', 'Successfully created');
				redirect('orders/update/' . $order_id, 'refresh');
			} else {
				$this->session->set_flashdata('errors', 'Error occurred!!');
				redirect('orders/create/', 'refresh');
			}
		} else {
			// false case
			$company = $this->model_company->getCompanyData(1);
			$this->data['company_data'] = $company;
			$this->data['is_vat_enabled'] = ($company['vat_charge_value'] > 0) ? true : false;
			$this->data['is_service_enabled'] = ($company['service_charge_value'] > 0) ? true : false;

			$this->data['products'] = $this->model_products->getActiveProductData();

			$this->render_template('orders/create', $this->data);
		}
	}

	/*
	* If the validation is not valid, then it redirects to the create page.
	* If the validation for each input field is valid then it inserts the data into the database 
	* and it stores the operation message into the session flashdata and display on the manage group page
	*/
	public function subbill($id)
	{
		if (!in_array('updateOrder', $this->permission)) {
			redirect('dashboard', 'refresh');
		}

		if (!$id) {
			redirect('dashboard', 'refresh');
		}

		$this->data['page_title'] = 'Cập nhật đơn hàng';

		$this->form_validation->set_rules('product[]', 'Tên sản phẩm', 'trim|required');


		if ($this->form_validation->run() == TRUE) {

			$order_id = $this->model_orders->createSubBill($id);

			if ($order_id) {
				$this->session->set_flashdata('success', 'Successfully created');
				redirect('orders/update/' . $order_id, 'refresh');
			} else {
				$this->session->set_flashdata('errors', 'Error occurred!!');
				redirect('orders/sub_create/', 'refresh');
			}
		}  else {
			// false case
			$company = $this->model_company->getCompanyData(1);
			$this->data['company_data'] = $company;
			$this->data['is_vat_enabled'] = ($company['vat_charge_value'] > 0) ? true : false;
			$this->data['is_service_enabled'] = ($company['service_charge_value'] > 0) ? true : false;

			$result = array();
			$orders_data = $this->model_orders->getOrdersData($id);

			$result['order'] = $orders_data;
			$orders_item = $this->model_orders->getOrdersItemData($orders_data['id']);

			foreach ($orders_item as $k => $v) {
				$result['order_item'][] = $v;
			}

			$this->data['order_data'] = $result;

			$this->data['products'] = $this->model_products->getActiveProductData();

			$this->render_template('orders/sub_create', $this->data);
		}
	}

	/*
	* It gets the product id passed from the ajax method.
	* It checks retrieves the particular product data from the product id 
	* and return the data into the json format.
	*/
	public function getProductValueById()
	{
		$product_id = $this->input->post('product_id');
		if ($product_id) {
			$product_data = $this->model_products->getProductData($product_id);
			echo json_encode($product_data);
		}
	}

	/*
	* It gets the all the active product inforamtion from the product table 
	* This function is used in the order page, for the product selection in the table
	* The response is return on the json format.
	*/
	public function getTableProductRow()
	{
		$products = $this->model_products->getActiveProductData();
		echo json_encode($products);
	}

	/*
	* If the validation is not valid, then it redirects to the edit orders page 
	* If the validation is successfully then it updates the data into the database 
	* and it stores the operation message into the session flashdata and display on the manage group page
	*/
	public function update($id)
	{
		if (!in_array('updateOrder', $this->permission)) {
			redirect('dashboard', 'refresh');
		}
		$sub_orders = $this->model_orders->getOrdersByParentId($id);
		if (!$id) {
			redirect('dashboard', 'refresh');
		}

		$this->data['page_title'] = 'Cập nhật đơn hàng';

		$this->form_validation->set_rules('product[]', 'Tên sản phẩm', 'trim|required');


		if ($this->form_validation->run() == TRUE) {

			$update = $this->model_orders->update($id);

			if ($update == true) {
				$this->session->set_flashdata('success', 'Cập nhật thành công');
				redirect('orders/update/' . $id, 'refresh');
			} else {
				$this->session->set_flashdata('errors', 'Error occurred!!');
				redirect('orders/update/' . $id, 'refresh');
			}
		} else {
			// false case
			$company = $this->model_company->getCompanyData(1);
			$this->data['company_data'] = $company;
			$this->data['is_vat_enabled'] = ($company['vat_charge_value'] > 0) ? true : false;
			$this->data['is_service_enabled'] = ($company['service_charge_value'] > 0) ? true : false;
			$this->data['sub_orders'] = $sub_orders;
 			$result = array();
			$orders_data = $this->model_orders->getOrdersData($id);

			$result['order'] = $orders_data;
			$orders_item = $this->model_orders->getOrdersItemData($orders_data['id']);

			foreach ($orders_item as $k => $v) {
				$result['order_item'][] = $v;
			}

			$this->data['order_data'] = $result;

			$this->data['products'] = $this->model_products->getActiveProductData();

			$this->render_template('orders/edit', $this->data);
		}
	}

	/*
	* It removes the data from the database
	* and it returns the response into the json format
	*/
	public function remove()
	{
		if (!in_array('deleteOrder', $this->permission)) {
			redirect('dashboard', 'refresh');
		}

		$order_id = $this->input->post('order_id');

		$response = array();
		if ($order_id) {
			$delete = $this->model_orders->remove($order_id);
			if ($delete == true) {
				$response['success'] = true;
				$response['messages'] = "Successfully removed";
			} else {
				$response['success'] = false;
				$response['messages'] = "Error in the database while removing the product information";
			}
		} else {
			$response['success'] = false;
			$response['messages'] = "Refersh the page again!!";
		}

		echo json_encode($response);
	}

	/*
	* It gets the product id and fetch the order data. 
	* The order print logic is done here 
	*/
	public function printDiv($id)
	{
		$mpdf = new \Mpdf\Mpdf();

		if (!in_array('viewOrder', $this->permission)) {
			redirect('dashboard', 'refresh');
		}

		if ($id) {
			$order_data = $this->model_orders->getOrdersData($id);
			$orders_items = $this->model_orders->getOrdersItemData($id);
			$company_info = $this->model_company->getCompanyData(1);

			$order_date = date('d/m/Y', $order_data['date_time']);
			$paid_status = ($order_data['paid_status'] == 1) ? "Đã thanh toán" : "Chưa thanh toán";

			$html = '<!-- Main content -->
			<!DOCTYPE html>
			<html>
			<head>
			  <meta charset="utf-8">
			  <meta http-equiv="X-UA-Compatible" content="IE=edge">
			  <title>Hóa đơn thanh toán | ' . $company_info['company_name'] . ' </title>
			  <!-- Tell the browser to be responsive to screen width -->
			  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
			  <!-- Bootstrap 3.3.7 -->
			  <link rel="stylesheet" href="' . base_url('assets/bower_components/bootstrap/dist/css/bootstrap.min.css') . '">
			  <!-- Font Awesome -->
			  <link rel="stylesheet" href="' . base_url('assets/bower_components/font-awesome/css/font-awesome.min.css') . '">
			  <link rel="stylesheet" href="' . base_url('assets/dist/css/AdminLTE.min.css') . '">
			</head>
			<body onload="window.print();">
			
			<div class="wrapper">
			  <section class="invoice">

			
				

				<table style="width:100%;">
					<tr>
						<td style="width: 80%; font-weight: bold"><b>VẬT LIỆU XÂY DỰNG </b>
					</tr>
					<tr>
						<td style="width: 80%; font-weight: bold"><h1>' . $company_info['company_name'] . '</h1></td>
						<td style="width: 20%">
							<div style="font-size: 17px;">
								HĐ : ' . $order_data['bill_no'] . '
							   </div>
						</td>
					</tr>

				</table>


				
				<div>
					<span style="font-weight: bold">ĐC: </span><span>' . $company_info['address'] . '	</span> 
				</div>
				<div>
					<span style="font-weight: bold">SĐT: </span><span>' . $company_info['phone'] . '	</span> 
				</div>
				<div style="margin-top: 10px">
					
					Họ tên khách hàng: <b>' . $order_data['customer_name'] . '</b> <br>
					Số điện thoại: <b>' . $order_data['customer_phone'] . '</b><br>
					Địa chỉ: ' . $order_data['customer_address'] . ' 
				</div>
			   
			    <!-- Table row -->
			    <div class="row" style="margin-top: 10px">
			      <div class="col-xs-12 table-responsive">
			        <table id="order-table" class="table table-bordered">
			          <thead>
					  <tr>
					  	<th style="padding: 5px">Số TT</th>
			            <th style="padding: 5px">TÊN HÀNG</th>
			            <th style="padding: 5px">ĐVT</th>
			            <th style="padding: 5px">Số lượng</th>
						<th style="padding: 5px">Đơn giá</th>
			            <th style="padding: 5px">THÀNH TIỀN</th>
						
			          </tr>
			          </thead>
			          <tbody>';

			foreach ($orders_items as $k => $v) {

				$product_data = $this->model_products->getProductData($v['product_id']);

				$html .= '<tr>
							<td style="padding: 3px; text-align: center">' . ($k + 1) . '</td>
							<td style="padding: 5px; width: 40%">' . $product_data['name'] . '</td>
							<td></td>
							<td style="width: 10%; text-align: center">' . $v['qty'] . '</td>
				            <td style="padding: 5px;">' . $v['rate'] . '</td>
				            <td style="padding: 5px;">' . number_format($v['amount']) . '</td>
			          	</tr>';
			}

			$html .= '
					  	<tr>
							<td style="padding: 5px;" colspan="2">TỔNG CỘNG</td>
							<td style="padding: 5px;" colspan="4"><b style="font-weight: bold">' . number_format($order_data['net_amount']) . '</b></td>
						</tr>';

			$html .= '
					<tr>
						  <td style="padding: 5px;" colspan="2">SỐ TIỀN BẰNG CHỮ</td>
						  <td style="padding: 5px; font-weight: bold" colspan="4"><b>' . $this->convert_number_to_words(round($order_data['net_amount'])) . '</b></td>
					  </tr>';
			$html .= '
					<tr>
						<td style="padding: 5px;" colspan="2">TRẠNG THÁI THANH TOÁN</td>
						<td style="padding: 5px; font-weight: bold" colspan="4"><b>' . $paid_status . '</b></td>
					</tr>';

			$html .= '</tbody>
			        </table>
			      </div>
			      <!-- /.col -->
			    </div>
				<!-- /.row -->
				
				<table style="width:100%; text-align: center">
					<tr>
						<td style="width: 50%"></td>
						<td style="width: 50%"><i>Ngày ' . (date("d")) . ' tháng ' . (date("m")) . ' năm ' . (date("Y")) . '</i></td>
					</tr>
					<tr>
						<td style="width: 50%; font-weight: bold">NGƯỜI NHẬN HÀNG</td>
						<td style="width: 50%; font-weight: bold">CHỦ CƠ SỞ</td>
					</tr>
					<tr>
						<td style="width: 50%;"></td>
						<td style="width: 50%">
							<br><br><br><br><br><br>
						<span style="font-weight: bold">HOÀNG VIỆT</span></td>
					</tr>
					

				</table>

				<div class="row">
					<div class="col-xs-12">
					<b><i>* Ghi chú: Cự ly giao hàng 20m</i></b>
					</div>
				</div>
		
			  </section>
			  <!-- /.content -->
			</div>
		</body>
	</html>';


			$mpdf->WriteHTML($html);
			$mpdf->Output(); // opens in browser
		}
	}
}

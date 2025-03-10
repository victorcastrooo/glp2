<?php
/**
 * SalesController.php - Controlador para gerenciamento de vendas pelo vendedor
 * 
 * Este controlador permite que os vendedores visualizem, filtrem e analisem
 * as vendas realizadas através de seus links de referência.
 */

namespace App\Controllers\Vendor;

use App\Models\OrderModel;
use App\Models\ProductModel;
use App\Models\CustomerModel;
use App\Models\DoctorModel;
use App\Models\ReferralLinkModel;
use App\Models\CommissionModel;

class SalesController {
    /**
     * @var OrderModel Modelo de pedidos
     */
    private $orderModel;
    
    /**
     * @var ProductModel Modelo de produtos
     */
    private $productModel;
    
    /**
     * @var CustomerModel Modelo de clientes
     */
    private $customerModel;
    
    /**
     * @var DoctorModel Modelo de médicos
     */
    private $doctorModel;
    
    /**
     * @var ReferralLinkModel Modelo de links de referência
     */
    private $referralLinkModel;
    
    /**
     * @var CommissionModel Modelo de comissões
     */
    private $commissionModel;
    
    /**
     * Construtor do controlador
     */
    public function __construct() {
        // Inicializar modelos
        $this->orderModel = new OrderModel();
        $this->productModel = new ProductModel();
        $this->customerModel = new CustomerModel();
        $this->doctorModel = new DoctorModel();
        $this->referralLinkModel = new ReferralLinkModel();
        $this->commissionModel = new CommissionModel();
        
        // Verificar se o usuário é vendedor
        if (!is_vendor()) {
            redirect('login');
        }
    }
    
    /**
     * Lista todas as vendas do vendedor
     */
    public function index() {
        $vendorId = get_current_user_id();
        
        // Parâmetros de paginação e filtros
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? 'all';
        $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        $doctorId = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;
        $linkId = isset($_GET['link_id']) ? (int)$_GET['link_id'] : 0;
        
        // Obter vendas com filtros aplicados
        $orders = $this->orderModel->getVendorOrdersWithFilters(
            $vendorId,
            $search,
            $status,
            $startDate,
            $endDate,
            $productId,
            $doctorId,
            $linkId,
            $limit,
            $offset
        );
        
        // Obter contagem total para paginação
        $totalOrders = $this->orderModel->countVendorOrdersWithFilters(
            $vendorId,
            $search,
            $status,
            $startDate,
            $endDate,
            $productId,
            $doctorId,
            $linkId
        );
        
        // Calcular total de páginas
        $totalPages = ceil($totalOrders / $limit);
        
        // Obter estatísticas do período
        $stats = $this->getSalesStats($vendorId, $startDate, $endDate);
        
        // Obter opções para filtros
        $products = $this->productModel->getAllBasic();
        $doctors = $this->doctorModel->getDoctorsByVendor($vendorId);
        $links = $this->referralLinkModel->getVendorLinks($vendorId);
        
        // Preparar dados para a view
        $data = [
            'title' => 'Minhas Vendas',
            'orders' => $orders,
            'stats' => $stats,
            'products' => $products,
            'doctors' => $doctors,
            'links' => $links,
            'search' => $search,
            'status' => $status,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'productId' => $productId,
            'doctorId' => $doctorId,
            'linkId' => $linkId,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalOrders' => $totalOrders
        ];
        
        // Renderizar a view
        view_with_layout('vendor/sales/index', $data, 'vendor');
    }
    
    /**
     * Exibe detalhes de uma venda
     * 
     * @param int $id ID do pedido
     */
    public function details($id) {
        $vendorId = get_current_user_id();
        
        // Obter pedido pelo ID
        $order = $this->orderModel->getVendorOrderDetails($id, $vendorId);
        
        if (!$order) {
            set_flash_message('error', 'Venda não encontrada ou não pertence a você.');
            redirect('vendedor/vendas');
            return;
        }
        
        // Obter itens do pedido
        $orderItems = $this->orderModel->getOrderItems($id);
        
        // Obter cliente
        $customer = $this->customerModel->getByUserId($order['user_id']);
        
        // Obter médico se houver
        $doctor = null;
        if ($order['doctor_id']) {
            $doctor = $this->doctorModel->getById($order['doctor_id']);
        }
        
        // Obter link de referência se houver
        $referralLink = null;
        if ($order['referral_id']) {
            $referralLink = $this->referralLinkModel->getById($order['referral_id']);
        }
        
        // Obter comissão associada
        $commission = $this->commissionModel->getByOrderId($id, $vendorId);
        
        // Obter histórico de status
        $statusHistory = $this->orderModel->getOrderStatusHistory($id);
        
        // Preparar dados para a view
        $data = [
            'title' => 'Detalhes da Venda #' . $order['order_number'],
            'order' => $order,
            'orderItems' => $orderItems,
            'customer' => $customer,
            'doctor' => $doctor,
            'referralLink' => $referralLink,
            'commission' => $commission,
            'statusHistory' => $statusHistory
        ];
        
        // Renderizar a view
        view_with_layout('vendor/sales/details', $data, 'vendor');
    }
    
    /**
     * Exibe relatório de vendas
     */
    public function report() {
        $vendorId = get_current_user_id();
        
        // Parâmetros de relatório
        $period = $_GET['period'] ?? 'month';
        $groupBy = $_GET['group_by'] ?? 'day';
        $chartType = $_GET['chart_type'] ?? 'sales';
        $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        $doctorId = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;
        
        // Definir datas com base no período
        list($startDate, $endDate) = $this->getDateRangeFromPeriod($period);
        
        // Obter dados para o relatório
        $reportData = $this->getReportData(
            $vendorId, 
            $startDate, 
            $endDate, 
            $groupBy, 
            $chartType, 
            $productId, 
            $doctorId
        );
        
        // Obter produtos para filtro
        $products = $this->productModel->getAllBasic();
        
        // Obter médicos para filtro
        $doctors = $this->doctorModel->getDoctorsByVendor($vendorId);
        
        // Preparar dados para a view
        $data = [
            'title' => 'Relatório de Vendas',
            'reportData' => $reportData,
            'period' => $period,
            'groupBy' => $groupBy,
            'chartType' => $chartType,
            'products' => $products,
            'doctors' => $doctors,
            'productId' => $productId,
            'doctorId' => $doctorId,
            'startDate' => $startDate,
            'endDate' => $endDate
        ];
        
        // Renderizar a view
        view_with_layout('vendor/sales/report', $data, 'vendor');
    }
    
    /**
     * Exporta lista de vendas em CSV
     */
    public function export() {
        $vendorId = get_current_user_id();
        
        // Obter filtros
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? 'all';
        $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        $doctorId = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;
        $linkId = isset($_GET['link_id']) ? (int)$_GET['link_id'] : 0;
        
        // Obter todos os pedidos com filtros
        $orders = $this->orderModel->getVendorOrdersWithFiltersNoLimit(
            $vendorId,
            $search,
            $status,
            $startDate,
            $endDate,
            $productId,
            $doctorId,
            $linkId
        );
        
        // Configurar cabeçalhos HTTP
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="minhas_vendas_' . date('Y-m-d') . '.csv"');
        
        // Criar handle de arquivo para output
        $output = fopen('php://output', 'w');
        
        // Adicionar BOM para UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Cabeçalhos
        fputcsv($output, [
            'Número', 'Data', 'Cliente', 'Médico', 'Link', 'Subtotal',
            'Frete', 'Desconto', 'Total', 'Comissão', 'Status'
        ]);
        
        // Dados
        foreach ($orders as $order) {
            // Formatar status
            $statusTranslated = '';
            switch ($order['status']) {
                case 'pending': $statusTranslated = 'Pendente'; break;
                case 'awaiting_prescription': $statusTranslated = 'Aguardando Receita'; break;
                case 'prescription_verified': $statusTranslated = 'Receita Verificada'; break;
                case 'awaiting_payment': $statusTranslated = 'Aguardando Pagamento'; break;
                case 'paid': $statusTranslated = 'Pago'; break;
                case 'processing': $statusTranslated = 'Em Processamento'; break;
                case 'shipped': $statusTranslated = 'Enviado'; break;
                case 'delivered': $statusTranslated = 'Entregue'; break;
                case 'cancelled': $statusTranslated = 'Cancelado'; break;
                case 'refunded': $statusTranslated = 'Reembolsado'; break;
                default: $statusTranslated = $order['status'];
            }
            
            fputcsv($output, [
                $order['order_number'],
                date('d/m/Y H:i', strtotime($order['created_at'])),
                $order['customer_name'],
                $order['doctor_name'] ?: 'N/A',
                $order['link_name'] ?: 'N/A',
                'R$ ' . number_format($order['subtotal'], 2, ',', '.'),
                'R$ ' . number_format($order['shipping_cost'], 2, ',', '.'),
                'R$ ' . number_format($order['discount'], 2, ',', '.'),
                'R$ ' . number_format($order['total'], 2, ',', '.'),
                'R$ ' . number_format($order['commission_amount'], 2, ',', '.'),
                $statusTranslated
            ]);
        }
        
        // Fechar handle de arquivo
        fclose($output);
        exit;
    }
    
    /**
     * Obtém estatísticas de vendas para o período especificado
     *
     * @param int $vendorId ID do vendedor
     * @param string $startDate Data inicial
     * @param string $endDate Data final
     * @return array Estatísticas de vendas
     */
    private function getSalesStats($vendorId, $startDate, $endDate) {
        // Total de vendas no período
        $totalSales = $this->orderModel->getTotalSalesByVendor($vendorId, $startDate, $endDate);
        
        // Número de pedidos no período
        $orderCount = $this->orderModel->getOrderCountByVendor($vendorId, $startDate, $endDate);
        
        // Valor médio dos pedidos
        $averageOrderValue = $orderCount > 0 ? $totalSales / $orderCount : 0;
        
        // Total de comissões no período
        $totalCommissions = $this->commissionModel->getTotalCommissionsByVendor($vendorId, $startDate, $endDate);
        
        // Produtos mais vendidos
        $topProducts = $this->orderModel->getTopProductsByVendor($vendorId, $startDate, $endDate, 5);
        
        // Médicos com mais prescrições
        $topDoctors = $this->orderModel->getTopDoctorsByVendor($vendorId, $startDate, $endDate, 5);
        
        // Links com mais conversões
        $topLinks = $this->orderModel->getTopLinksByVendor($vendorId, $startDate, $endDate, 5);
        
        return [
            'totalSales' => $totalSales,
            'orderCount' => $orderCount,
            'averageOrderValue' => $averageOrderValue,
            'totalCommissions' => $totalCommissions,
            'topProducts' => $topProducts,
            'topDoctors' => $topDoctors,
            'topLinks' => $topLinks
        ];
    }
    
    /**
     * Obtém dados para o relatório de vendas
     *
     * @param int $vendorId ID do vendedor
     * @param string $startDate Data inicial
     * @param string $endDate Data final
     * @param string $groupBy Agrupamento (day, week, month, year)
     * @param string $chartType Tipo de gráfico (sales, orders, commissions)
     * @param int $productId ID do produto (opcional)
     * @param int $doctorId ID do médico (opcional)
     * @return array Dados para o relatório
     */
    private function getReportData($vendorId, $startDate, $endDate, $groupBy, $chartType, $productId = 0, $doctorId = 0) {
        switch ($chartType) {
            case 'sales':
                return $this->orderModel->getSalesReportByVendor(
                    $vendorId, $startDate, $endDate, $groupBy, $productId, $doctorId
                );
                
            case 'orders':
                return $this->orderModel->getOrdersReportByVendor(
                    $vendorId, $startDate, $endDate, $groupBy, $productId, $doctorId
                );
                
            case 'commissions':
                return $this->commissionModel->getCommissionsReportByVendor(
                    $vendorId, $startDate, $endDate, $groupBy, $productId, $doctorId
                );
                
            case 'products':
                return $this->orderModel->getProductsReportByVendor(
                    $vendorId, $startDate, $endDate, $productId, $doctorId
                );
                
            case 'doctors':
                return $this->orderModel->getDoctorsReportByVendor(
                    $vendorId, $startDate, $endDate, $productId
                );
                
            default:
                return [];
        }
    }
    
    /**
     * Retorna o intervalo de datas com base no período selecionado
     * 
     * @param string $period Período selecionado (month, quarter, year, etc)
     * @return array Array com data inicial e final
     */
    private function getDateRangeFromPeriod($period) {
        $endDate = date('Y-m-d');
        $startDate = '';
        
        switch ($period) {
            case 'week':
                $startDate = date('Y-m-d', strtotime('-7 days'));
                break;
            
            case 'month':
                $startDate = date('Y-m-01');
                break;
            
            case 'quarter':
                $startDate = date('Y-m-d', strtotime('first day of -3 months'));
                break;
            
            case 'year':
                $startDate = date('Y-01-01');
                break;
            
            case 'lastmonth':
                $startDate = date('Y-m-01', strtotime('first day of last month'));
                $endDate = date('Y-m-t', strtotime('last day of last month'));
                break;
            
            case 'lastyear':
                $year = date('Y') - 1;
                $startDate = "$year-01-01";
                $endDate = "$year-12-31";
                break;
            
            default:
                $startDate = date('Y-m-d', strtotime('-30 days'));
        }
        
        return [$startDate, $endDate];
    }
}
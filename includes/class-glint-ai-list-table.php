<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class Glint_AI_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( array(
			'singular' => 'log',
			'plural'   => 'logs',
			'ajax'     => false
		) );
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_submenu' ) );
	}

	public static function add_submenu() {
		$hook = add_submenu_page(
			'glint-ai-spam-killer',
			'Email Logs',
			'Email Logs',
			'manage_options',
			'glint-ai-spam-logs',
			array( __CLASS__, 'render_page' )
		);
		add_action( "load-$hook", array( __CLASS__, 'process_actions' ) );
	}

	public static function process_actions() {
		$action = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : '';
		$action2 = isset( $_REQUEST['action2'] ) ? $_REQUEST['action2'] : '';
		$current_action = $action ? $action : $action2;

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['clear_all_blocked'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'clear_all_blocked' ) ) {
			global $wpdb;
			$table_name = Glint_AI_DB::get_table_name();
			$wpdb->delete( $table_name, array( 'status' => 'blocked' ) );
			wp_redirect( admin_url( 'admin.php?page=glint-ai-spam-logs' ) );
			exit;
		}

		if ( ! $current_action || $current_action === '-1' ) {
			return;
		}

		check_admin_referer( 'bulk-' . (isset($_GET['page']) ? $_GET['page'] : '') );
		
		$log_ids = isset( $_REQUEST['log'] ) ? (array) $_REQUEST['log'] : array();
		if ( empty( $log_ids ) && isset($_REQUEST['log_id']) ) {
			$log_ids = array( $_REQUEST['log_id'] );
		}

		if ( empty( $log_ids ) ) {
			return;
		}

		global $wpdb;
		$table_name = Glint_AI_DB::get_table_name();

		if ( 'delete' === $current_action ) {
			foreach ( $log_ids as $id ) {
				$wpdb->delete( $table_name, array( 'id' => intval( $id ) ) );
			}
		} elseif ( 'force_send' === $current_action ) {
			foreach ( $log_ids as $id ) {
				Glint_AI_Interceptor::force_send( intval( $id ) );
			}
		}

		$redirect_url = admin_url( 'admin.php?page=glint-ai-spam-logs' );
		if ( isset( $_REQUEST['status'] ) ) {
			$redirect_url = add_query_arg( 'status', sanitize_text_field( $_REQUEST['status'] ), $redirect_url );
		}
		wp_redirect( $redirect_url );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		
		$list_table = new self();
		$list_table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Email Logs</h1>
			<?php 
			$clear_url = wp_nonce_url( admin_url('admin.php?page=glint-ai-spam-logs&clear_all_blocked=1'), 'clear_all_blocked' );
			?>
			<a href="<?php echo esc_url( $clear_url ); ?>" class="page-title-action" onclick="return confirm('Are you sure you want to delete ALL blocked logs?');">Clear All Blocked</a>
			<hr class="wp-header-end">
			<form id="logs-filter" method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( $_REQUEST['page'] ); ?>" />
				<?php if ( isset( $_REQUEST['status'] ) ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $_REQUEST['status'] ); ?>" />
				<?php endif; ?>
				<?php
				$list_table->views();
				$list_table->display();
				?>
			</form>
		</div>
		<style>
			.badge { padding: 3px 8px; border-radius: 3px; font-weight: bold; color: #fff; display: inline-block; }
			.badge-pending { background-color: #f39c12; }
			.badge-blocked { background-color: #e74c3c; }
			.badge-sent { background-color: #2ecc71; }
			.message-cell, .reason-cell { max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
		</style>
		<?php
	}

	protected function get_views() {
		global $wpdb;
		$table_name = Glint_AI_DB::get_table_name();

		$views = array();
		$current = isset( $_REQUEST['status'] ) ? sanitize_text_field( $_REQUEST['status'] ) : 'all';

		$statuses = array(
			'all' => 'All',
			'pending' => 'Pending',
			'blocked' => 'Blocked',
			'sent' => 'Sent'
		);

		foreach ( $statuses as $key => $name ) {
			$where = ( $key !== 'all' ) ? $wpdb->prepare( "WHERE status = %s", $key ) : '';
			$count = $wpdb->get_var( "SELECT COUNT(id) FROM $table_name $where" );
			
			$url = admin_url( 'admin.php?page=glint-ai-spam-logs' );
			if ( $key !== 'all' ) {
				$url = add_query_arg( 'status', $key, $url );
			}
			
			$class = ( $current === $key ) ? 'class="current"' : '';
			$views[ $key ] = sprintf( '<a href="%s" %s>%s <span class="count">(%d)</span></a>', esc_url( $url ), $class, $name, $count );
		}

		return $views;
	}

	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'created_at' => 'Date',
			'to_email'   => 'To Email',
			'subject'    => 'Subject',
			'message'    => 'Message',
			'status'     => 'Status',
			'ai_reason'  => 'AI Reason'
		);
	}

	public function get_sortable_columns() {
		return array(
			'created_at' => array( 'created_at', true ),
			'to_email'   => array( 'to_email', false ),
		);
	}

	protected function get_bulk_actions() {
		return array(
			'force_send' => 'Force Send',
			'delete'     => 'Delete'
		);
	}

	public function prepare_items() {
		global $wpdb;
		$table_name = Glint_AI_DB::get_table_name();

		$per_page = 20;
		$current_page = $this->get_pagenum();

		$where = '';
		if ( isset( $_REQUEST['status'] ) && in_array( $_REQUEST['status'], array( 'pending', 'blocked', 'sent' ) ) ) {
			$where = $wpdb->prepare( "WHERE status = %s", $_REQUEST['status'] );
		}

		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( $_REQUEST['orderby'] ) : 'created_at';
		$order = isset( $_REQUEST['order'] ) ? sanitize_text_field( $_REQUEST['order'] ) : 'desc';

		$valid_orderbys = array( 'created_at', 'to_email' );
		if ( ! in_array( $orderby, $valid_orderbys ) ) {
			$orderby = 'created_at';
		}
		$order = ( 'asc' === strtolower( $order ) ) ? 'ASC' : 'DESC';

		$total_items = $wpdb->get_var( "SELECT COUNT(id) FROM $table_name $where" );

		$offset = ( $current_page - 1 ) * $per_page;
		$items = $wpdb->get_results( "SELECT * FROM $table_name $where ORDER BY $orderby $order LIMIT $offset, $per_page", ARRAY_A );

		$this->items = $items;
		$this->set_pagination_args( array(
			'total_items' => $total_items,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total_items / $per_page )
		) );
		
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="log[]" value="%d" />',
			$item['id']
		);
	}

	public function column_created_at( $item ) {
		$actions = array();
		
		if ( in_array( $item['status'], array( 'pending', 'blocked' ) ) ) {
			$force_send_url = wp_nonce_url( admin_url( 'admin.php?page=glint-ai-spam-logs&action=force_send&log_id=' . $item['id'] ), 'bulk-' . $_REQUEST['page'] );
			$actions['force_send'] = sprintf( '<a href="%s">Force Send</a>', esc_url( $force_send_url ) );
		}
		
		$delete_url = wp_nonce_url( admin_url( 'admin.php?page=glint-ai-spam-logs&action=delete&log_id=' . $item['id'] ), 'bulk-' . $_REQUEST['page'] );
		$actions['delete'] = sprintf( '<a href="%s" class="delete" style="color:red">Delete</a>', esc_url( $delete_url ) );

		return sprintf( '%1$s %2$s', esc_html( $item['created_at'] ), $this->row_actions( $actions ) );
	}

	public function column_to_email( $item ) {
		return esc_html( $item['to_email'] );
	}

	public function column_subject( $item ) {
		return esc_html( $item['subject'] );
	}

	public function column_message( $item ) {
		return '<div class="message-cell" title="' . esc_attr( $item['message'] ) . '">' . esc_html( $item['message'] ) . '</div>';
	}

	public function column_status( $item ) {
		$status = $item['status'];
		$class = 'badge-pending';
		if ( 'blocked' === $status ) {
			$class = 'badge-blocked';
		} elseif ( 'sent' === $status ) {
			$class = 'badge-sent';
		}
		return sprintf( '<span class="badge %s">%s</span>', esc_attr( $class ), esc_html( ucfirst( $status ) ) );
	}

	public function column_ai_reason( $item ) {
		return '<div class="reason-cell" title="' . esc_attr( $item['ai_reason'] ) . '">' . esc_html( $item['ai_reason'] ) . '</div>';
	}

	public function column_default( $item, $column_name ) {
		return esc_html( $item[ $column_name ] );
	}
}

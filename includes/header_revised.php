                    <!-- Leave Management -->
                    <li class="nav-item <?php echo ($current_module == 'leave') ? 'menu-open' : ''; ?>">
                        <a href="#" class="nav-link" data-toggle="dropdown">
                            <i class="nav-icon fas fa-calendar-alt"></i>
                            <span>Leave Management</span>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/leave/index.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Leave Dashboard</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/leave/apply.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Apply for Leave</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/leave/my-leaves.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>My Leaves</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/leave/balance.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Leave Balance</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/leave/approvals.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Leave Approvals</span></a></li>
                            <li class="nav-item"><a href="<?php echo $base_path; ?>modules/leave/leave-types.php" class="nav-link"><i class="far fa-circle nav-icon"></i><span>Leave Types</span></a></li>
                        </ul>
                    </li>


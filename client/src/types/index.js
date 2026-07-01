// HRM System Type Definitions based on Database Schema

/**
 * @typedef {Object} User
 * @property {number} id
 * @property {string} name
 * @property {string} email
 * @property {string} [password]
 * @property {number} role_id
 * @property {string} [remember_token]
 * @property {string} [created_at]
 * @property {string} [updated_at]
 * @property {Role} [role]
 */

/**
 * @typedef {Object} Role
 * @property {number} id
 * @property {string} code
 * @property {string} name
 * @property {string} [description]
 * @property {Object} [permissions]
 * @property {boolean} is_active
 * @property {string} [created_at]
 * @property {string} [updated_at]
 */

/**
 * @typedef {Object} Department
 * @property {number} id
 * @property {string} code
 * @property {string} name
 * @property {number} [manager_id]
 * @property {number} [parent_id]
 * @property {boolean} is_active
 * @property {string} [created_at]
 * @property {string} [updated_at]
 * @property {Employee} [manager]
 * @property {Department} [parent]
 * @property {Department[]} [children]
 * @property {Employee[]} [employees]
 */

/**
 * @typedef {Object} JobTitle
 * @property {number} id
 * @property {string} code
 * @property {string} name
 * @property {'entry'|'junior'|'senior'|'lead'|'manager'|'director'|'executive'} job_level
 * @property {number} [job_family_id]
 * @property {boolean} is_active
 * @property {string} [created_at]
 * @property {string} [updated_at]
 * @property {JobFamily} [job_family]
 */

/**
 * @typedef {Object} JobFamily
 * @property {number} id
 * @property {string} code
 * @property {string} name
 * @property {string} [description]
 * @property {boolean} is_active
 * @property {string} [created_at]
 * @property {string} [updated_at]
 */

/**
 * @typedef {Object} Employee
 * @property {number} id
 * @property {string} code
 * @property {number} [user_id]
 * @property {string} full_name
 * @property {'M'|'F'|'O'} [gender]
 * @property {string} [dob]
 * @property {string} [personal_email]
 * @property {string} [personal_phone]
 * @property {string} [emergency_contact_name]
 * @property {string} [emergency_contact_phone]
 * @property {string} [id_number]
 * @property {string} [id_issue_date]
 * @property {string} [id_issue_place]
 * @property {string} [tax_number]
 * @property {string} [insurance_number]
 * @property {string} [address]
 * @property {string} [created_at]
 * @property {string} [updated_at]
 * @property {User} [user]
 * @property {EmploymentHistory} [current_position]
 * @property {Department} [department]
 * @property {JobTitle} [job_title]
 */

/**
 * @typedef {Object} EmploymentHistory
 * @property {number} id
 * @property {number} employee_id
 * @property {number} department_id
 * @property {number} job_title_id
 * @property {string} start_date
 * @property {string} [end_date]
 * @property {number} [salary]
 * @property {'head_office'|'branch_1'|'branch_2'|'remote'} [work_location]
 * @property {'full_time'|'part_time'|'contract'|'intern'} [employment_type]
 * @property {'active'|'probation'|'suspended'|'inactive'} [employment_status]
 * @property {number} [report_to]
 * @property {string} [created_at]
 * @property {string} [updated_at]
 * @property {Employee} [employee]
 * @property {Department} [department]
 * @property {JobTitle} [job_title]
 */

/**
 * @typedef {Object} LeaveType
 * @property {number} id
 * @property {string} code
 * @property {string} name
 * @property {'annual'|'sick'|'maternity'|'paternity'|'unpaid'|'other'} category
 * @property {boolean} paid
 * @property {number} max_days
 * @property {number} [accrual_rate]
 * @property {number} carry_over_days
 * @property {boolean} is_active
 * @property {string} [created_at]
 * @property {string} [updated_at]
 */

/**
 * @typedef {Object} LeaveBalance
 * @property {number} id
 * @property {number} employee_id
 * @property {number} leave_type_id
 * @property {number} year
 * @property {number} total_entitled
 * @property {number} used
 * @property {number} remaining
 * @property {number} carried_over
 * @property {string} [created_at]
 * @property {string} [updated_at]
 * @property {Employee} [employee]
 * @property {LeaveType} [leave_type]
 */

/**
 * @typedef {Object} LeaveRequest
 * @property {number} id
 * @property {number} employee_id
 * @property {number} leave_type_id
 * @property {string} start_date
 * @property {string} end_date
 * @property {number} total_days
 * @property {string} [reason]
 * @property {'draft'|'pending'|'approved'|'rejected'|'cancelled'} status
 * @property {number} [approved_by]
 * @property {string} [approved_at]
 * @property {string} [created_at]
 * @property {string} [updated_at]
 * @property {Employee} [employee]
 * @property {LeaveType} [leave_type]
 * @property {User} [approver]
 */

/**
 * @typedef {Object} WorkShift
 * @property {number} id
 * @property {string} code
 * @property {string} name
 * @property {string} start_time
 * @property {string} end_time
 * @property {number} break_minutes
 * @property {number} [total_hours]
 * @property {boolean} is_default
 * @property {boolean} is_active
 * @property {string} [created_at]
 * @property {string} [updated_at]
 */

/**
 * @typedef {Object} WorkSchedule
 * @property {number} id
 * @property {number} employee_id
 * @property {string} work_date
 * @property {number} shift_id
 * @property {string} [actual_start_time]
 * @property {string} [actual_end_time]
 * @property {number} [total_hours]
 * @property {number} [overtime_hours]
 * @property {'scheduled'|'present'|'absent'|'late'|'half_day'} [status]
 * @property {string} [note]
 * @property {string} [created_at]
 * @property {string} [updated_at]
 * @property {Employee} [employee]
 * @property {WorkShift} [shift]
 */

/**
 * @typedef {Object} AttendanceRecord
 * @property {number} id
 * @property {number} employee_id
 * @property {string} record_date
 * @property {string} [check_in_time]
 * @property {string} [check_out_time]
 * @property {number} [total_work_hours]
 * @property {number} [late_minutes]
 * @property {number} [early_leave_minutes]
 * @property {number} [overtime_hours]
 * @property {'present'|'absent'|'late'|'half_day'|'holiday'} status
 * @property {string} [created_at]
 * @property {string} [updated_at]
 * @property {Employee} [employee]
 */

/**
 * @typedef {Object} SalaryComponent
 * @property {number} id
 * @property {string} code
 * @property {string} name
 * @property {'earning'|'deduction'} type
 * @property {'basic'|'allowance'|'bonus'|'tax'|'insurance'|'other'} category
 * @property {boolean} is_taxable
 * @property {boolean} is_active
 * @property {string} [created_at]
 * @property {string} [updated_at]
 */

/**
 * @typedef {Object} EmployeeSalary
 * @property {number} id
 * @property {number} employee_id
 * @property {number} salary_component_id
 * @property {number} amount
 * @property {string} effective_date
 * @property {string} [end_date]
 * @property {string} [created_at]
 * @property {string} [updated_at]
 * @property {Employee} [employee]
 * @property {SalaryComponent} [salary_component]
 */

/**
 * @typedef {Object} ActivityLog
 * @property {number} id
 * @property {string} table_name
 * @property {number} record_id
 * @property {'create'|'update'|'delete'|'view'} action
 * @property {number} [by_user_id]
 * @property {string} at
 * @property {Object} [old_values]
 * @property {Object} [new_values]
 * @property {string} [ip_address]
 * @property {string} [user_agent]
 * @property {User} [user]
 */

/**
 * @typedef {Object} DashboardStats
 * @property {number} totalEmployees
 * @property {number} activeDepartments
 * @property {number} pendingLeaves
 * @property {Object} todayAttendance
 * @property {number} todayAttendance.present
 * @property {number} todayAttendance.absent
 * @property {number} todayAttendance.late
 */

/**
 * @typedef {Object} AttendanceTrend
 * @property {string} date
 * @property {number} present
 * @property {number} absent
 * @property {number} late
 */

/**
 * @typedef {Object} LeaveTrend
 * @property {string} month
 * @property {number} approved
 * @property {number} pending
 * @property {number} rejected
 */

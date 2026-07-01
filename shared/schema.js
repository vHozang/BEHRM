import { sql } from "drizzle-orm";
import { pgTable, text, varchar, integer, timestamp, boolean, decimal, date, time, pgEnum, smallint, json } from "drizzle-orm/pg-core";
import { createInsertSchema } from "drizzle-zod";
import { z } from "zod";

// Enums
export const jobLevelEnum = pgEnum('job_level', ['entry', 'junior', 'senior', 'lead', 'manager', 'director', 'executive']);
export const leaveStatusEnum = pgEnum('leave_status', ['pending', 'approved', 'rejected', 'cancelled', 'draft']);
export const attendanceStatusEnum = pgEnum('attendance_status', ['present', 'absent', 'late', 'leave', 'half_day', 'holiday']);
export const leaveCategoryEnum = pgEnum('leave_category', ['annual', 'sick', 'maternity', 'paternity', 'unpaid', 'other']);
export const workLocationEnum = pgEnum('work_location', ['head_office', 'branch_1', 'branch_2', 'remote']);
export const employmentTypeEnum = pgEnum('employment_type', ['full_time', 'part_time', 'contract', 'intern']);
export const employmentStatusEnum = pgEnum('employment_status', ['active', 'probation', 'suspended', 'inactive']);
export const scheduleStatusEnum = pgEnum('schedule_status', ['scheduled', 'present', 'absent', 'late', 'half_day']);
export const salaryComponentTypeEnum = pgEnum('salary_component_type', ['earning', 'deduction']);
export const salaryComponentCategoryEnum = pgEnum('salary_component_category', ['basic', 'allowance', 'bonus', 'tax', 'insurance', 'other']);
export const activityActionEnum = pgEnum('activity_action', ['create', 'update', 'delete', 'view']);

// Users table
export const users = pgTable("users", {
  id: integer("id").primaryKey().generatedAlwaysAsIdentity(),
  name: text("name").notNull(),
  email: text("email").notNull().unique(),
  password: text("password").notNull(),
  role_id: integer("role_id").references(() => roles.id),
  remember_token: text("remember_token"),
  created_at: timestamp("created_at").defaultNow(),
  updated_at: timestamp("updated_at").defaultNow(),
});

// Roles table
export const roles = pgTable("roles", {
  id: integer("id").primaryKey().generatedAlwaysAsIdentity(),
  code: varchar("code", { length: 50 }).notNull().unique(),
  name: varchar("name", { length: 100 }).notNull(),
  description: text("description"),
  permissions: text("permissions"),
  is_active: boolean("is_active").default(true),
  created_at: timestamp("created_at").defaultNow(),
  updated_at: timestamp("updated_at").defaultNow(),
});

// Departments table
export const departments = pgTable("departments", {
  id: integer("id").primaryKey().generatedAlwaysAsIdentity(),
  code: varchar("code", { length: 50 }).notNull().unique(),
  name: varchar("name", { length: 100 }).notNull(),
  manager_id: integer("manager_id"),
  parent_id: integer("parent_id"),
  is_active: boolean("is_active").default(true),
  created_at: timestamp("created_at").defaultNow(),
  updated_at: timestamp("updated_at").defaultNow(),
});

// Job Families table
export const jobFamilies = pgTable("job_families", {
  id: integer("id").primaryKey().generatedAlwaysAsIdentity(),
  code: varchar("code", { length: 50 }).notNull().unique(),
  name: varchar("name", { length: 100 }).notNull(),
  description: text("description"),
  is_active: boolean("is_active").default(true),
  created_at: timestamp("created_at").defaultNow(),
  updated_at: timestamp("updated_at").defaultNow(),
});

// Job Titles table
export const jobTitles = pgTable("job_titles", {
  id: integer("id").primaryKey().generatedAlwaysAsIdentity(),
  code: varchar("code", { length: 50 }).notNull().unique(),
  name: varchar("name", { length: 100 }).notNull(),
  job_level: jobLevelEnum("job_level"),
  job_family_id: integer("job_family_id").references(() => jobFamilies.id),
  is_active: boolean("is_active").default(true),
  created_at: timestamp("created_at").defaultNow(),
  updated_at: timestamp("updated_at").defaultNow(),
});

// Employees table
export const employees = pgTable("employees", {
  id: integer("id").primaryKey().generatedAlwaysAsIdentity(),
  employee_code: varchar("employee_code", { length: 50 }).notNull().unique(),
  first_name: varchar("first_name", { length: 50 }),
  last_name: varchar("last_name", { length: 50 }),
  full_name: varchar("full_name", { length: 100 }).notNull(),
  email: varchar("email", { length: 100 }).unique(),
  phone: varchar("phone", { length: 20 }),
  date_of_birth: date("date_of_birth"),
  gender: varchar("gender", { length: 10 }),
  address: text("address"),
  department_id: integer("department_id").references(() => departments.id),
  job_title_id: integer("job_title_id").references(() => jobTitles.id),
  manager_id: integer("manager_id"),
  hire_date: date("hire_date"),
  user_id: integer("user_id").references(() => users.id),
  personal_email: varchar("personal_email", { length: 120 }),
  personal_phone: varchar("personal_phone", { length: 20 }),
  emergency_contact_name: varchar("emergency_contact_name", { length: 100 }),
  emergency_contact_phone: varchar("emergency_contact_phone", { length: 20 }),
  id_number: varchar("id_number", { length: 50 }),
  id_issue_date: date("id_issue_date"),
  id_issue_place: varchar("id_issue_place", { length: 100 }),
  tax_number: varchar("tax_number", { length: 50 }),
  insurance_number: varchar("insurance_number", { length: 50 }),
  is_active: boolean("is_active").default(true),
  created_at: timestamp("created_at").defaultNow(),
  updated_at: timestamp("updated_at").defaultNow(),
});

// Work Shifts table
export const workShifts = pgTable("work_shifts", {
  id: integer("id").primaryKey().generatedAlwaysAsIdentity(),
  code: varchar("code", { length: 50 }).notNull().unique(),
  name: varchar("name", { length: 150 }).notNull(),
  start_time: time("start_time").notNull(),
  end_time: time("end_time").notNull(),
  break_minutes: smallint("break_minutes").default(0),
  total_hours: decimal("total_hours", { precision: 4, scale: 2 }),
  is_default: boolean("is_default").default(false),
  is_active: boolean("is_active").default(true),
  created_at: timestamp("created_at").defaultNow(),
  updated_at: timestamp("updated_at").defaultNow(),
});

// Work Schedules table
export const workSchedules = pgTable("work_schedules", {
  id: integer("id").primaryKey().generatedAlwaysAsIdentity(),
  employee_id: integer("employee_id").notNull().references(() => employees.id),
  work_date: date("work_date").notNull(),
  shift_id: integer("shift_id").notNull().references(() => workShifts.id),
  actual_start_time: timestamp("actual_start_time"),
  actual_end_time: timestamp("actual_end_time"),
  total_hours: decimal("total_hours", { precision: 4, scale: 2 }),
  overtime_hours: decimal("overtime_hours", { precision: 4, scale: 2 }),
  status: scheduleStatusEnum("status").default('scheduled'),
  note: varchar("note", { length: 255 }),
  created_at: timestamp("created_at").defaultNow(),
  updated_at: timestamp("updated_at").defaultNow(),
});

// Attendance table
export const attendance = pgTable("attendance", {
  id: integer("id").primaryKey().generatedAlwaysAsIdentity(),
  employee_id: integer("employee_id").notNull().references(() => employees.id),
  attendance_date: date("attendance_date").notNull(),
  check_in_time: timestamp("check_in_time"),
  check_out_time: timestamp("check_out_time"),
  total_work_hours: decimal("total_work_hours", { precision: 4, scale: 2 }),
  late_minutes: smallint("late_minutes"),
  early_leave_minutes: smallint("early_leave_minutes"),
  overtime_hours: decimal("overtime_hours", { precision: 4, scale: 2 }),
  status: attendanceStatusEnum("status").default('present'),
  notes: text("notes"),
  created_at: timestamp("created_at").defaultNow(),
  updated_at: timestamp("updated_at").defaultNow(),
});

// Leave Types table
export const leaveTypes = pgTable("leave_types", {
  id: integer("id").primaryKey().generatedAlwaysAsIdentity(),
  code: varchar("code", { length: 50 }).notNull().unique(),
  name: varchar("name", { length: 150 }).notNull(),
  category: leaveCategoryEnum("category"),
  paid: boolean("paid").default(true),
  max_days: smallint("max_days").default(12),
  accrual_rate: decimal("accrual_rate", { precision: 4, scale: 2 }),
  carry_over_days: smallint("carry_over_days").default(0),
  is_active: boolean("is_active").default(true),
  created_at: timestamp("created_at").defaultNow(),
  updated_at: timestamp("updated_at").defaultNow(),
});

// Leave Balances table
export const leaveBalances = pgTable("leave_balances", {
  id: integer("id").primaryKey().generatedAlwaysAsIdentity(),
  employee_id: integer("employee_id").notNull().references(() => employees.id),
  leave_type_id: integer("leave_type_id").notNull().references(() => leaveTypes.id),
  year: smallint("year").notNull(),
  total_entitled: decimal("total_entitled", { precision: 5, scale: 2 }).notNull(),
  used: decimal("used", { precision: 5, scale: 2 }).default('0'),
  remaining: decimal("remaining", { precision: 5, scale: 2 }).default('0'),
  carried_over: decimal("carried_over", { precision: 5, scale: 2 }).default('0'),
  created_at: timestamp("created_at").defaultNow(),
  updated_at: timestamp("updated_at").defaultNow(),
});

// Leave Requests table
export const leaveRequests = pgTable("leave_requests", {
  id: integer("id").primaryKey().generatedAlwaysAsIdentity(),
  employee_id: integer("employee_id").notNull().references(() => employees.id),
  leave_type_id: integer("leave_type_id").references(() => leaveTypes.id),
  leave_type: varchar("leave_type", { length: 50 }),
  start_date: date("start_date").notNull(),
  end_date: date("end_date").notNull(),
  days_count: decimal("days_count", { precision: 5, scale: 2 }),
  total_days: decimal("total_days", { precision: 5, scale: 2 }),
  reason: text("reason"),
  status: leaveStatusEnum("status").default('pending'),
  approved_by: integer("approved_by").references(() => users.id),
  approved_at: timestamp("approved_at"),
  created_at: timestamp("created_at").defaultNow(),
  updated_at: timestamp("updated_at").defaultNow(),
});

// Salary Components table
export const salaryComponents = pgTable("salary_components", {
  id: integer("id").primaryKey().generatedAlwaysAsIdentity(),
  code: varchar("code", { length: 50 }).notNull().unique(),
  name: varchar("name", { length: 150 }).notNull(),
  type: salaryComponentTypeEnum("type"),
  category: salaryComponentCategoryEnum("category"),
  is_taxable: boolean("is_taxable").default(true),
  is_active: boolean("is_active").default(true),
  created_at: timestamp("created_at").defaultNow(),
  updated_at: timestamp("updated_at").defaultNow(),
});

// Employee Salaries table
export const employeeSalaries = pgTable("employee_salaries", {
  id: integer("id").primaryKey().generatedAlwaysAsIdentity(),
  employee_id: integer("employee_id").notNull().references(() => employees.id),
  salary_component_id: integer("salary_component_id").notNull().references(() => salaryComponents.id),
  amount: decimal("amount", { precision: 12, scale: 0 }).notNull(),
  effective_date: date("effective_date").notNull(),
  end_date: date("end_date"),
  created_at: timestamp("created_at").defaultNow(),
  updated_at: timestamp("updated_at").defaultNow(),
});

// Salaries table (monthly payroll)
export const salaries = pgTable("salaries", {
  id: integer("id").primaryKey().generatedAlwaysAsIdentity(),
  employee_id: integer("employee_id").notNull().references(() => employees.id),
  month: varchar("month", { length: 7 }).notNull(),
  base_salary: decimal("base_salary", { precision: 15, scale: 2 }).notNull(),
  allowances: decimal("allowances", { precision: 15, scale: 2 }).default('0'),
  bonuses: decimal("bonuses", { precision: 15, scale: 2 }).default('0'),
  deductions: decimal("deductions", { precision: 15, scale: 2 }).default('0'),
  net_salary: decimal("net_salary", { precision: 15, scale: 2 }).notNull(),
  working_days: integer("working_days").notNull(),
  actual_days: decimal("actual_days", { precision: 5, scale: 2 }).notNull(),
  created_at: timestamp("created_at").defaultNow(),
  updated_at: timestamp("updated_at").defaultNow(),
});

// Employment Histories table
export const employmentHistories = pgTable("employment_histories", {
  id: integer("id").primaryKey().generatedAlwaysAsIdentity(),
  employee_id: integer("employee_id").notNull().references(() => employees.id),
  department_id: integer("department_id").references(() => departments.id),
  job_title_id: integer("job_title_id").references(() => jobTitles.id),
  start_date: date("start_date").notNull(),
  end_date: date("end_date"),
  salary: decimal("salary", { precision: 12, scale: 0 }),
  work_location: workLocationEnum("work_location"),
  employment_type: employmentTypeEnum("employment_type"),
  employment_status: employmentStatusEnum("employment_status"),
  report_to: integer("report_to").references(() => employees.id),
  created_at: timestamp("created_at").defaultNow(),
  updated_at: timestamp("updated_at").defaultNow(),
});

// Activity Logs table
export const activityLogs = pgTable("activity_logs", {
  id: integer("id").primaryKey().generatedAlwaysAsIdentity(),
  table_name: varchar("table_name", { length: 100 }).notNull(),
  record_id: integer("record_id").notNull(),
  action: activityActionEnum("action").notNull(),
  by_user_id: integer("by_user_id").references(() => users.id),
  at: timestamp("at").defaultNow(),
  old_values: json("old_values"),
  new_values: json("new_values"),
  ip_address: varchar("ip_address", { length: 45 }),
  user_agent: varchar("user_agent", { length: 255 }),
});

// Insert schemas
export const insertUserSchema = createInsertSchema(users).omit({ id: true, created_at: true, updated_at: true });
export const insertRoleSchema = createInsertSchema(roles).omit({ id: true, created_at: true, updated_at: true });
export const insertDepartmentSchema = createInsertSchema(departments).omit({ id: true, created_at: true, updated_at: true });
export const insertEmployeeSchema = createInsertSchema(employees).omit({ id: true, created_at: true, updated_at: true });
export const insertAttendanceSchema = createInsertSchema(attendance).omit({ id: true, created_at: true, updated_at: true });
export const insertLeaveRequestSchema = createInsertSchema(leaveRequests).omit({ id: true, created_at: true, updated_at: true });
export const insertSalarySchema = createInsertSchema(salaries).omit({ id: true, created_at: true, updated_at: true });
export const insertJobTitleSchema = createInsertSchema(jobTitles).omit({ id: true, created_at: true, updated_at: true });
export const insertJobFamilySchema = createInsertSchema(jobFamilies).omit({ id: true, created_at: true, updated_at: true });
export const insertWorkShiftSchema = createInsertSchema(workShifts).omit({ id: true, created_at: true, updated_at: true });
export const insertWorkScheduleSchema = createInsertSchema(workSchedules).omit({ id: true, created_at: true, updated_at: true });
export const insertLeaveTypeSchema = createInsertSchema(leaveTypes).omit({ id: true, created_at: true, updated_at: true });
export const insertLeaveBalanceSchema = createInsertSchema(leaveBalances).omit({ id: true, created_at: true, updated_at: true });
export const insertSalaryComponentSchema = createInsertSchema(salaryComponents).omit({ id: true, created_at: true, updated_at: true });
export const insertEmployeeSalarySchema = createInsertSchema(employeeSalaries).omit({ id: true, created_at: true, updated_at: true });
export const insertEmploymentHistorySchema = createInsertSchema(employmentHistories).omit({ id: true, created_at: true, updated_at: true });
export const insertActivityLogSchema = createInsertSchema(activityLogs).omit({ id: true, at: true });

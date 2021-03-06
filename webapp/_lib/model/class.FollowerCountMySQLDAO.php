<?php
/**
 *
 * ThinkUp/webapp/_lib/model/class.FollowerCountMySQLDAO.php
 *
 * Copyright (c) 2009-2011 Gina Trapani
 *
 * LICENSE:
 *
 * This file is part of ThinkUp (http://thinkupapp.com).
 *
 * ThinkUp is free software: you can redistribute it and/or modify it under the terms of the GNU General Public
 * License as published by the Free Software Foundation, either version 2 of the License, or (at your option) any
 * later version.
 *
 * ThinkUp is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more
 * details.
 *
 * You should have received a copy of the GNU General Public License along with ThinkUp.  If not, see
 * <http://www.gnu.org/licenses/>.
 *
 *
 * Follower Count MySQL Data Access Object Implementation
 *
 * @license http://www.gnu.org/licenses/gpl.html
 * @copyright 2009-2010 Gina Trapani
 * @author Gina Trapani <ginatrapani[at]gmail[dot]com>
 *
 */
class FollowerCountMySQLDAO extends PDODAO implements FollowerCountDAO {
    public function insert($network_user_id, $network, $count){
        $q  = " INSERT INTO #prefix#follower_count ";
        $q .= " (network_user_id, network, date, count) ";
        $q .= " VALUES ( :network_user_id, :network, NOW(), :count );";
        $vars = array(
            ':network_user_id'=>$network_user_id, 
            ':network'=>$network,
            ':count'=>$count
        );
        $ps = $this->execute($q, $vars);
        return $this->getInsertCount($ps);
    }

    public function getHistory($network_user_id, $network, $group_by, $limit=10) {
        if ($group_by != "DAY" && $group_by != 'WEEK' && $group_by != 'MONTH') {
            $group_by = 'DAY';
        }
        if ($group_by == 'DAY') {
            $group_by = 'fc.date';
        } else if ($group_by == 'WEEK') {
            $group_by = 'YEAR(fc.date), WEEK(fc.date)';
        } else if ($group_by == 'MONTH') {
            $group_by = 'YEAR(fc.date), MONTH(fc.date)';
        }
        $q = "SELECT network_user_id, network, count, date, full_date FROM ";
        $q .= "(SELECT network_user_id, network, count, DATE_FORMAT(date, '%c/%e') as date, date as full_date ";
        $q .= "FROM #prefix#follower_count AS fc ";
        $q .= "WHERE fc.network_user_id = :network_user_id AND fc.network=:network ";
        $q .= "GROUP BY ".$group_by." ORDER BY full_date DESC LIMIT :limit ) as history_counts ";
        $q .= "ORDER BY history_counts.full_date ASC";
        $vars = array(
            ':network_user_id'=>$network_user_id,
            ':network'=>$network,
            ':limit'=>(int)$limit
        );
        $ps = $this->execute($q, $vars);
        $history_rows = $this->getDataRowsAsArrays($ps);

        if (sizeof($history_rows) > 1 ) {
            //break down rows into a simpler date=>count assoc array
            $simplified_history = array();
            foreach ($history_rows as $history_row) {
                $simplified_history[$history_row["date"]] = $history_row["count"];
            }

            $trend = false;
            if (sizeof($history_rows) == $limit) { //we have a complete data set
                //calculate the trend
                $first_follower_count = reset($simplified_history);
                $last_follower_count = end($simplified_history);
                $trend = ($last_follower_count - $first_follower_count)/sizeof($simplified_history);
                $trend = round($trend);
                //complete data set
                $history = $simplified_history;
            } else { //there are dates with missing data
                //set up an array of all the dates to show in the chart
                $dates_to_display = array();
                $format = 'n/j';
                $date = date ( $format );
                $i = $limit;
                while ($i > 0 ) {
                    $date_ago = date ($format, strtotime('-'.$i.' day'.$date));
                    $dates_to_display[$date_ago] = "no data";
                    $i--;
                }
                //merge the data we do have with the dates we want
                $history = array_merge($dates_to_display, $simplified_history);
            }

            //calculate the point percentages
            $percentages = array();

            $max_count = intval($history_rows[0]['count']);
            $min_count = intval($history_rows[0]['count']);
            foreach ($history_rows as $row) {
                $min_count = ($row['count'] < $min_count)?intval($row['count']):$min_count;
                $max_count = ($row['count'] > $max_count)?intval($row['count']):$max_count;
            }
            $difference = $max_count - $min_count;
            foreach ($history as $data_point) {
                if ($data_point == 'no data') {
                    $percentages[] = 0;
                } else {
                    $amount_above_min = $data_point - $min_count;
                    $percentages[] = round(Utils::getPercentage($amount_above_min, $difference));
                }
            }

            $y_axis = array();
            $num_y_axis_points = 4;
            $y_axis_interval_size = $difference/$num_y_axis_points;
            $i = 0;
            while ($i < $num_y_axis_points) {
                $y_axis[$i] = $min_count + ($y_axis_interval_size * $i);
                $i = $i+1;
            }
            $y_axis[$num_y_axis_points] = $max_count;
        } else  {
            $history = false;
            $y_axis = false;
            $trend = false;
            $percentages = false;
        }
        return array('history'=>$history, 'percentages'=>$percentages, 'y_axis'=>$y_axis, 'trend'=>$trend);
    }
}